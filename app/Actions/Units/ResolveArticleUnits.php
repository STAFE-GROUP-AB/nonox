<?php

declare(strict_types=1);

namespace App\Actions\Units;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\MaterialConversions;
use App\Domain\Units\UnitCatalogue;
use App\Domain\Units\UnitConverter;
use App\Domain\Units\UnitDefaults;
use App\Domain\Units\UnitRegister;
use App\Models\Article;
use App\Models\Unit;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Illuminate\Validation\ValidationException;

/**
 * Works out an article's stock, purchase and sales unit, its material
 * size and its conversions from what a save sends (ADR-071 §12.2, §12.3),
 * and refuses what cannot work: a unit of another company, an archived
 * unit, a unit with no way to convert to the stock unit, a stock unit
 * changed under existing stock. Runs inside the company; SaveArticle
 * calls it.
 *
 * Input keys, all optional: stock_unit_id, purchase_unit_id, sales_unit_id
 * (null for purchase or sales means the stock unit), material_length,
 * material_width, material_thickness (millimetres as written, up to three
 * decimals) and conversions (a list of unit and per: how many of that
 * unit one stock unit is). An article written with only a unit text, as
 * before, moves all three units to the unit that text names.
 */
final class ResolveArticleUnits
{
    private const array UNIT_KEYS = ['stock_unit_id', 'purchase_unit_id', 'sales_unit_id'];

    /** The same three, named by code (m³, lpm) for imports, the demo and the API. */
    private const array CODE_KEYS = ['stock_unit' => 'stock_unit_id', 'purchase_unit' => 'purchase_unit_id', 'sales_unit' => 'sales_unit_id'];

    private const array DIMENSIONS = ['material_length' => 'material_length_um', 'material_width' => 'material_width_um', 'material_thickness' => 'material_thickness_um'];

    /**
     * True when the input names units by id or code, so the unit text is not needed.
     *
     * @param  array<string, mixed>  $input
     */
    public static function isExplicit(array $input): bool
    {
        return array_intersect([...self::UNIT_KEYS, ...array_keys(self::CODE_KEYS)], array_keys($input)) !== [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{attributes: array<string, mixed>, manual: ?array<string, BigRational>}
     *
     * @throws ValidationException
     */
    public function __invoke(int $companyId, ?Article $article, array $input, ?string $unitText): array
    {
        $input = $this->idsFromCodes($companyId, $input);
        $attributes = $this->units($companyId, $article, $input, $unitText);

        foreach (self::DIMENSIONS as $key => $column) {
            if (array_key_exists($key, $input)) {
                $attributes[$column] = $this->micrometres($input[$key], $key);
            }
        }

        $stockCode = Unit::query()->findOrFail((int) $attributes['stock_unit_id'])->code;
        $manual = array_key_exists('conversions', $input) ? $this->manual($input['conversions'], $stockCode) : null;

        $this->checkDerivedFit($article, $attributes, $stockCode);
        $this->checkConversions($article, $attributes, $manual, $stockCode);

        return ['attributes' => $attributes, 'manual' => $manual];
    }

    /**
     * Units named by code become ids; a code the catalogue does not have is refused.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function idsFromCodes(int $companyId, array $input): array
    {
        foreach (self::CODE_KEYS as $codeKey => $idKey) {
            if (! array_key_exists($codeKey, $input)) {
                continue;
            }

            $code = trim((string) $input[$codeKey]);
            unset($input[$codeKey]);

            if ($code === '') {
                $input[$idKey] = null;

                continue;
            }

            UnitCatalogue::seed($companyId);
            $key = UnitDefaults::normalise($code);
            $unit = Unit::query()->get()->first(fn (Unit $unit): bool => mb_strtolower($unit->code) === $key || in_array($key, $unit->aliases, true));

            $input[$idKey] = $unit === null
                ? throw ValidationException::withMessages([$codeKey => __('Enheten :unit finns inte i enhetslistan.', ['unit' => $code])])
                : $unit->id;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function units(int $companyId, ?Article $article, array $input, ?string $unitText): array
    {
        if (! self::isExplicit($input)) {
            // The text must name a unit of the register (ADR-071 §12.9); importers add unknown spellings first (UnitCatalogue::resolve).
            UnitCatalogue::seed($companyId);
            $unit = UnitRegister::load()->match((string) $unitText)
                ?? throw ValidationException::withMessages(['unit' => __('Enheten :unit finns inte i enhetslistan. Välj en enhet ur listan, eller lägg till den under Enheter.', ['unit' => mb_substr(trim((string) $unitText), 0, 20)])]);

            if ($unit->isArchived() && $unit->id !== ($article === null ? null : ($article->sales_unit_id ?? $article->stock_unit_id))) {
                throw ValidationException::withMessages(['unit' => __('Enheten :unit är arkiverad och kan inte väljas.', ['unit' => $unit->code])]);
            }
            $simple = $article === null
                || ($article->stock_unit_id === null)
                || (($article->purchase_unit_id ?? $article->stock_unit_id) === $article->stock_unit_id && ($article->sales_unit_id ?? $article->stock_unit_id) === $article->stock_unit_id);

            // The text is kept as typed ("tim" stays "tim"): invoices print what the company wrote.
            $text = trim((string) $unitText);

            return $simple
                ? ['stock_unit_id' => $unit->id, 'purchase_unit_id' => $unit->id, 'sales_unit_id' => $unit->id, 'unit' => $text]
                : ['stock_unit_id' => $article->stock_unit_id, 'purchase_unit_id' => $article->purchase_unit_id, 'sales_unit_id' => $unit->id, 'unit' => $text];
        }

        UnitCatalogue::seed($companyId);

        $stockId = $this->id($input, 'stock_unit_id', $article?->stock_unit_id);

        if ($stockId === null) {
            throw ValidationException::withMessages(['stock_unit_id' => __('Välj lagerenhet.')]);
        }

        $purchaseId = $this->id($input, 'purchase_unit_id', $article?->purchase_unit_id);
        $salesId = $this->id($input, 'sales_unit_id', $article?->sales_unit_id);

        $found = [];

        foreach (['stock_unit_id' => $stockId, 'purchase_unit_id' => $purchaseId, 'sales_unit_id' => $salesId] as $key => $id) {
            if ($id === null) {
                continue;
            }

            $unit = Unit::query()->find($id) ?? throw ValidationException::withMessages([$key => __('Enheten finns inte.')]);

            if ($unit->isArchived() && $id !== $article?->{$key}) {
                throw ValidationException::withMessages([$key => __('Enheten :unit är arkiverad och kan inte väljas.', ['unit' => $unit->code])]);
            }

            $found[$key] = $unit;
        }

        if ($article !== null && $article->stock_unit_id !== null && $stockId !== $article->stock_unit_id && $this->hasStock($article)) {
            throw ValidationException::withMessages(['stock_unit_id' => __('Lagerenheten kan inte ändras när artikeln har lagerrörelser eller ingår i recept. Skapa en ny artikel i den nya enheten.')]);
        }

        // The text stays as it is when the sales unit does not change ("tim" is not turned into "h").
        $unchanged = $article !== null && ($salesId ?? $stockId) === ($article->sales_unit_id ?? $article->stock_unit_id) && (string) $article->unit !== '';

        return [
            'stock_unit_id' => $stockId,
            'purchase_unit_id' => $purchaseId,
            'sales_unit_id' => $salesId,
            'unit' => $unchanged ? $article->unit : ($found['sales_unit_id'] ?? $found['stock_unit_id'])->code,
        ];
    }

    /** @param  array<string, mixed>  $input */
    private function id(array $input, string $key, ?int $current): ?int
    {
        if (! array_key_exists($key, $input)) {
            return $current;
        }

        return ($input[$key] ?? '') === '' ? null : (int) $input[$key];
    }

    private function hasStock(Article $article): bool
    {
        return StockMovement::query()->where('article_id', $article->id)->exists()
            || BomRow::query()->where('component_article_id', $article->id)->exists();
    }

    /**
     * The conversions typed on the form: unit code => how many of it one stock unit is.
     *
     * @return array<string, BigRational>
     */
    private function manual(mixed $rows, string $stockCode): array
    {
        $result = [];
        $units = UnitRegister::load();

        foreach ((array) $rows as $index => $row) {
            $unit = UnitDefaults::normalise((string) ($row['unit'] ?? ''));
            $per = trim((string) ($row['per'] ?? ''));

            if ($unit === '' && $per === '') {
                continue;
            }

            if ($unit === '' || mb_strlen($unit) > 10) {
                throw ValidationException::withMessages(["conversions.{$index}.unit" => __('Välj enheten omräkningen gäller.')]);
            }

            if ($units->match($unit) === null) {
                throw ValidationException::withMessages(["conversions.{$index}.unit" => __('Enheten :unit finns inte i enhetslistan. Välj en enhet ur listan, eller lägg till den under Enheter.', ['unit' => $unit])]);
            }

            if (UnitConverter::enteredPerStock($stockCode, $unit) !== null) {
                throw ValidationException::withMessages(["conversions.{$index}.unit" => __(':unit räknas redan om utan omräkning.', ['unit' => $unit])]);
            }

            $fraction = ArticleUnits::parseFraction($per);

            if ($fraction === null) {
                throw ValidationException::withMessages(["conversions.{$index}.per" => __('Skriv hur många :unit som går på en :stock. Mer än noll, högst nio siffror före och nio efter kommatecknet.', ['unit' => $unit, 'stock' => $stockCode])]);
            }

            $result[UnitDefaults::codeFor($unit) ?? $unit] = $fraction;
        }

        return $result;
    }

    /**
     * Every unit other than the stock unit must convert to it: built in,
     * derived from the size, or entered.
     *
     * @param  array<string, mixed>  $attributes
     * @param  ?array<string, BigRational>  $manual
     */
    private function checkConversions(?Article $article, array $attributes, ?array $manual, string $stockCode): void
    {
        $others = array_filter(['purchase_unit_id' => $attributes['purchase_unit_id'], 'sales_unit_id' => $attributes['sales_unit_id']], fn (mixed $id): bool => $id !== null && $id !== $attributes['stock_unit_id']);

        if ($others === []) {
            return;
        }

        $dimension = fn (string $column): ?int => array_key_exists($column, $attributes) ? $attributes[$column] : $article?->{$column};
        $known = MaterialConversions::for($stockCode, $dimension('material_length_um'), $dimension('material_width_um'), $dimension('material_thickness_um'));

        $entered = $manual ?? ($article === null ? [] : ArticleUnits::fractions(ArticleUnitConversion::query()->where('article_id', $article->id)->where('source', ArticleUnitConversion::MANUAL)->get()));

        foreach ($others as $key => $id) {
            $code = Unit::query()->findOrFail((int) $id)->code;

            if (UnitConverter::enteredPerStock($stockCode, $code, [...$known, ...$entered]) === null) {
                throw ValidationException::withMessages([$key => __('Det saknas en omräkning mellan :stock och :unit. Lägg till den under Omräkningar, eller ange måtten om den kan räknas fram.', ['stock' => $stockCode, 'unit' => $code])]);
            }
        }
    }

    /**
     * Sizes whose derived conversions would not fit the columns (a 100 m
     * board in all three directions) are refused with the size fields.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function checkDerivedFit(?Article $article, array $attributes, string $stockCode): void
    {
        $size = fn (string $column): ?int => array_key_exists($column, $attributes) ? $attributes[$column] : $article?->{$column};

        foreach (MaterialConversions::for($stockCode, $size('material_length_um'), $size('material_width_um'), $size('material_thickness_um')) as $fraction) {
            if (! ArticleUnits::fits($fraction)) {
                throw ValidationException::withMessages(['material_length' => __('Måtten är för stora för att räkna om mellan enheterna. Kontrollera att de är i millimeter.')]);
            }
        }
    }

    private function micrometres(mixed $value, string $key): ?int
    {
        $text = str_replace(',', '.', str_replace([' ', "\u{00A0}"], '', trim((string) $value)));

        if ($text === '') {
            return null;
        }

        $message = __('Skriv måttet i millimeter med högst tre decimaler, till exempel 45 eller 0,6.');

        if (preg_match('/^\d+(\.\d{1,3})?$/', $text) !== 1) {
            throw ValidationException::withMessages([$key => $message]);
        }

        try {
            $um = BigDecimal::of($text)->multipliedBy(1000)->toInt();
        } catch (MathException) {
            throw ValidationException::withMessages([$key => $message]);
        }

        if ($um <= 0 || $um > 100_000_000) {
            throw ValidationException::withMessages([$key => __('Måttet måste vara mer än noll och högst 100 000 mm.')]);
        }

        return $um;
    }
}
