<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Structures;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\QuantityRoundsToZero;
use App\Domain\Units\UnitConverter;
use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Domain\StructureUnits;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Saves a new version of a structure (ADR-073 §2). The only way structures
 * are written, for Kök's recipes and later manufacturing's bills of
 * materials. It does not check the caller's permissions; the action that
 * calls it does (as StockLedger). Every save is a new version: the yield
 * must be above zero, a loss is 0–99,99 %, every component exists and has
 * a unit the row can be stored in, and no component may lead back to the
 * output article through another structure (a loop).
 */
final class StructureWriter
{
    public const int MAX_ROWS = 200;

    /** First key of the PostgreSQL advisory lock for structure writes; the second is the company. */
    private const int LOCK_KEY = 73_001;

    /**
     * Rows of the last save whose conversion to the stock unit changed what
     * was typed by more than 0,5 % (ADR-071 §12.4): the row is saved, the
     * screen may show the warning. Each is text for the person.
     *
     * @var list<string>
     */
    public array $warnings = [];

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * The input as the form sends it: name, valid_from (Y-m-d, today when
     * empty), yield and yield_unit (PORTIONS or UNIT), method, allergens
     * (a list or comma-separated text), and rows of article_id, quantity
     * as written ("1,5"), unit (the article's when empty), loss in percent
     * ("12,5") and note.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function save(Company $company, ?User $by, StructureKind $kind, Article $output, array $input): BillOfMaterials
    {
        $this->modules->ensureEnabled(InventoryModule::KEY, $company);
        $this->warnings = [];

        return CurrentCompany::run($company, fn (): BillOfMaterials => DB::transaction(function () use ($company, $by, $kind, $output, $input): BillOfMaterials {
            // One structure save per company at a time, so two saves cannot close a loop between them.
            self::lock($company);
            Article::query()->whereKey($output->id)->lockForUpdate()->firstOrFail();

            $name = trim((string) ($input['name'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages(['name' => __('Ge receptet ett namn.')]);
            }

            $yield = self::quantity((string) ($input['yield'] ?? ''), 'yield');

            if ($yield <= 0) {
                throw ValidationException::withMessages(['yield' => __('Ange hur mycket receptet ger, mer än noll.')]);
            }

            $yieldUnit = (string) ($input['yield_unit'] ?? '');

            if (! in_array($yieldUnit, [BillOfMaterials::PORTIONS, BillOfMaterials::UNIT], true)) {
                throw ValidationException::withMessages(['yield_unit' => __('Välj om receptet ger portioner eller en mängd.')]);
            }

            try {
                $validFrom = CarbonImmutable::parse((string) (($input['valid_from'] ?? null) ?: CarbonImmutable::today()->toDateString()))->startOfDay();
            } catch (Throwable) {
                throw ValidationException::withMessages(['valid_from' => __('Datumet går inte att läsa.')]);
            }

            $rows = array_values(array_filter((array) ($input['rows'] ?? []), fn (mixed $row): bool => is_array($row) && ($row['article_id'] ?? '') !== '' && ($row['article_id'] ?? null) !== null));

            if ($rows === []) {
                throw ValidationException::withMessages(['rows' => __('Lägg till minst en ingrediens.')]);
            }

            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['rows' => __('Ett recept kan ha högst :max rader.', ['max' => self::MAX_ROWS])]);
            }

            $articles = Article::query()->with('stockUnit')->whereKey(array_map(fn (array $row): int => (int) $row['article_id'], $rows))->get()->keyBy('id');
            $conversions = ArticleUnitConversion::query()->whereIn('article_id', $articles->keys()->all())->get()
                ->groupBy('article_id')
                ->map(fn ($group): array => ArticleUnits::fractions($group));
            $book = StructureBook::for($company);
            $prepared = [];

            foreach ($rows as $index => $row) {
                $article = $articles->get((int) $row['article_id']) ?? throw ValidationException::withMessages(["rows.{$index}.article_id" => __('Artikeln finns inte.')]);

                if ($article->id === $output->id) {
                    throw ValidationException::withMessages(["rows.{$index}.article_id" => __('Ett recept kan inte innehålla sig självt.')]);
                }

                if ($book->yieldsPortions($article->id)) {
                    throw ValidationException::withMessages(["rows.{$index}.article_id" => __(':name är en rätt med utbyte i portioner och kan inte vara en ingrediens. Ge grundreceptet utbyte i artikelns enhet.', ['name' => $article->name])]);
                }

                if ($book->reaches($article->id, $output->id)) {
                    throw ValidationException::withMessages(["rows.{$index}.article_id" => __(':name innehåller redan :output, så receptet skulle gå i en ring.', ['name' => $article->name, 'output' => $output->name])]);
                }

                $entered = self::quantity((string) ($row['quantity'] ?? ''), "rows.{$index}.quantity");

                if ($entered <= 0) {
                    throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Mängden måste vara mer än noll.')]);
                }

                $stockCode = ArticleUnits::stockCode($article);
                $unit = StructureUnits::normalise((string) (($row['unit'] ?? null) ?: $stockCode));
                $perStock = UnitConverter::enteredPerStock($stockCode, $unit, $conversions->get($article->id, []));

                if ($perStock === null) {
                    throw ValidationException::withMessages(["rows.{$index}.unit" => __(':name har enheten :unit. Lägg till en omräkning från :entered på artikeln, eller skriv mängden i :unit.', ['name' => $article->name, 'unit' => $stockCode, 'entered' => $unit])]);
                }

                try {
                    $conversion = UnitConverter::toStock(Quantity::decimal($entered), $perStock);
                } catch (QuantityRoundsToZero) {
                    throw ValidationException::withMessages(["rows.{$index}.quantity" => __('Mängden blir noll i lagerenheten. Skriv en större mängd.')]);
                }

                $quantity = $conversion->stockMilli;
                $sameUnit = $perStock->isEqualTo(1);

                if ($conversion->warns) {
                    $this->warnings[] = __(':name: :entered :unit blir :stock :stockUnit i lagerenheten, en ändring på :percent %.', ['name' => $article->name, 'entered' => Quantity::format($entered), 'unit' => $unit, 'stock' => Quantity::format($quantity), 'stockUnit' => $stockCode, 'percent' => $conversion->changePercent]);
                }

                $loss = self::loss($row['loss'] ?? null, "rows.{$index}.loss");

                $prepared[] = [
                    'component_article_id' => $article->id,
                    'quantity_milli' => $quantity,
                    'loss_bp' => $loss,
                    'entered_quantity' => $sameUnit ? null : Quantity::decimal($entered),
                    'entered_unit' => $sameUnit ? null : $unit,
                    'position' => $index,
                    'note' => ($row['note'] ?? '') === '' ? null : mb_substr(trim((string) $row['note']), 0, 255),
                ];
            }

            $allergens = $input['allergens'] ?? null;

            if (is_string($allergens)) {
                $allergens = array_values(array_filter(array_map(fn (string $tag): string => mb_substr(trim($tag), 0, 40), explode(',', $allergens)), fn (string $tag): bool => $tag !== ''));
            }

            $usedIn = $book->usedIn($output->id);

            if ($yieldUnit === BillOfMaterials::PORTIONS && $usedIn !== []) {
                throw ValidationException::withMessages(['yield_unit' => __(':name används som ingrediens i :count andra recept och måste ge en mängd i artikelns enhet, inte portioner.', ['name' => $output->name, 'count' => count($usedIn)])]);
            }

            $structure = BillOfMaterials::create([
                'kind' => $kind,
                'output_article_id' => $output->id,
                'name' => mb_substr($name, 0, 255),
                'version' => (int) BillOfMaterials::query()->where('output_article_id', $output->id)->max('version') + 1,
                'valid_from' => $validFrom->toDateString(),
                'is_active' => true,
                'yield_milli' => $yield,
                'yield_unit' => $yieldUnit,
                'method' => ($input['method'] ?? '') === '' ? null : mb_substr((string) $input['method'], 0, 20_000),
                'allergens' => $allergens === [] ? null : $allergens,
                'created_by' => $by?->id,
            ]);

            foreach ($prepared as $row) {
                BomRow::create(['bill_of_materials_id' => $structure->id, ...$row]);
            }

            return $structure->load('rows');
        }));
    }

    /**
     * Switches every version of the output article off or on. Switching on
     * is refused when it would close a loop or put a portion recipe in
     * another recipe.
     */
    public function setActive(Company $company, Article $output, bool $active): int
    {
        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($company, $output, $active): int {
            self::lock($company);
            $versions = BillOfMaterials::query()->where('output_article_id', $output->id)->with('rows')->get();
            $versions->each(fn (BillOfMaterials $version) => $version->forceFill(['is_active' => $active])->save());

            if ($active) {
                $book = StructureBook::for($company);

                foreach ($versions as $version) {
                    foreach ($version->rows as $row) {
                        if ($row->component_article_id !== null && $book->reaches((int) $row->component_article_id, $output->id)) {
                            throw ValidationException::withMessages(['active' => __('Receptet kan inte användas igen: det skulle gå i en ring med ett annat recept.')]);
                        }
                    }

                    if ($version->isPortions() && $book->usedIn($output->id) !== []) {
                        throw ValidationException::withMessages(['active' => __(':name används som ingrediens i andra recept och kan inte ge portioner.', ['name' => $output->name])]);
                    }
                }
            }

            return $versions->count();
        }));
    }

    /** A per-company transaction lock for structure writes (released at commit). */
    private static function lock(Company $company): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_KEY, $company->id]);
    }

    private static function quantity(string $text, string $field): int
    {
        try {
            return Quantity::parse($text);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Mängden går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }
    }

    /** A loss in percent as written ("12,5") as basis points, 0–9 999. */
    private static function loss(mixed $value, string $field): int
    {
        $text = str_replace([' ', ','], ['', '.'], trim((string) ($value ?? '')));

        if ($text === '') {
            return 0;
        }

        try {
            $bp = BigDecimal::of($text)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw ValidationException::withMessages([$field => __('Svinnet går inte att läsa. Skriv procent, till exempel 10 eller 12,5.')]);
        }

        if ($bp < 0 || $bp >= StructureMath::BP) {
            throw ValidationException::withMessages([$field => __('Svinnet måste vara från 0 till under 100 procent.')]);
        }

        return $bp;
    }
}
