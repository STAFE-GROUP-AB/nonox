<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Actions;

use App\Models\Article;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Structures\StructureBook;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Kitchen\Actions\Concerns\GuardsKitchen;
use App\Modules\Kitchen\Domain\WasteReason;
use App\Modules\Kitchen\KitchenSettings;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\Kitchen\Models\WasteMeasurement;
use App\Modules\Kitchen\Support\RecipeStock;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * The waste log (ADR-073 §6), from the page and from the scanner's Svinn
 * tile. An ingredient is written off as it is; a dish or sub-recipe is
 * exploded through its recipe into its stocked ingredients. Each becomes a
 * SCRAP movement through StockLedger with the reason code and the entry as
 * source. No voucher: waste is no affärshändelse (RS-R10), the books meet
 * the stock at the year-end valuation. Registering means the goods have
 * left the stock (RS-R5). A date in a locked period is allowed.
 *
 * A wrong entry is corrected by a new entry with the opposite quantity,
 * which puts the goods back with RETURN movements; the log is append-only.
 * Plate waste is a measurement in kg with no stock movement.
 *
 * A donation (SKANKT) records the receiving organisation and proposes no
 * VAT (RS-R11). TODO(legal-verify) L6: Skatteverket's position that donated
 * near-expiry food normally has no tax base, read only through LR 2026.
 * TODO(legal-verify) L5: whether the waste log is räkenskapsinformation; it
 * is kept as long as the stock movements either way.
 */
class RegisterWaste
{
    use GuardsKitchen;

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $input  date, kind (INGREDIENT or DISH), article_id, quantity as written, location_id, reason, recipient, note
     */
    public function __invoke(User $user, Company $company, array $input): WasteEntry
    {
        $this->guard($user, $company, 'register');

        $validated = Validator::make($input, [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::in([WasteEntry::INGREDIENT, WasteEntry::DISH])],
            'article_id' => ['required', 'integer'],
            'quantity' => ['required', 'string', 'max:20'],
            'location_id' => ['nullable', 'integer'],
            'reason' => ['required', Rule::enum(WasteReason::class)],
            'recipient' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'reason.required' => __('Välj varför det slängs.'),
            'article_id.required' => __('Välj vad som slängs.'),
        ])->validate();

        $date = CarbonImmutable::parse($validated['date'] ?? CarbonImmutable::today()->toDateString())->startOfDay();

        if ($date->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['date' => __('Registrera svinnet när det har slängts, inte i förväg.')]);
        }

        $reason = WasteReason::from((string) $validated['reason']);
        $recipient = trim((string) ($validated['recipient'] ?? ''));
        $note = trim((string) ($validated['note'] ?? ''));

        if ($reason === WasteReason::Donated && $recipient === '') {
            throw ValidationException::withMessages(['recipient' => __('Skriv vilken organisation som fick maten.')]);
        }

        if ($reason === WasteReason::Other && $note === '') {
            throw ValidationException::withMessages(['note' => __('Skriv vad som hände när orsaken är Övrigt.')]);
        }

        $quantity = self::quantity((string) $validated['quantity']);

        return CurrentCompany::run($company, fn (): WasteEntry => DB::transaction(function () use ($user, $company, $validated, $date, $reason, $recipient, $note, $quantity): WasteEntry {
            $article = Article::query()->findOrFail((int) $validated['article_id']);
            $location = $this->location($company, $validated['location_id'] ?? null, $article);
            $lines = $this->lines($company, (string) $validated['kind'], $article, $quantity, $date);

            $entry = WasteEntry::create([
                'registered_on' => $date->toDateString(),
                'kind' => $validated['kind'],
                'article_id' => $article->id,
                'quantity_milli' => $quantity,
                'location_id' => $location->id,
                'reason' => $reason,
                'recipient' => $reason === WasteReason::Donated ? mb_substr($recipient, 0, 120) : null,
                'note' => $note === '' ? null : $note,
                'created_by' => $user->id,
            ]);

            $this->ledger->post($company, $user, MovementType::Scrap, array_map(fn (int $articleId, int $milli): array => [
                'article_id' => $articleId,
                'location_id' => $location->id,
                'quantity_milli' => -$milli,
                'reason' => $reason->value,
            ], array_keys($lines), array_values($lines)), $date, $entry, __('Svinn: :reason', ['reason' => $reason->label()]));

            Audit::record('kitchen.waste_registered', $entry, [
                'kind' => $entry->kind,
                'article_id' => $article->id,
                'quantity_milli' => $quantity,
                'reason' => $reason->value,
                'ingredients' => count($lines),
            ], $company, $user);

            return $entry;
        }));
    }

    /**
     * Puts the goods of an entry back (RETURN, in, at today's cost per unit)
     * and records the correction as a new entry with the opposite quantity.
     */
    public function correct(User $user, Company $company, WasteEntry $entry, ?string $note = null): WasteEntry
    {
        $this->guard($user, $company, 'register');

        return CurrentCompany::run($company, fn (): WasteEntry => DB::transaction(function () use ($user, $company, $entry, $note): WasteEntry {
            $entry = WasteEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->corrects_entry_id !== null) {
                throw ValidationException::withMessages(['entry' => __('En rättelse kan inte rättas. Registrera svinnet på nytt i stället.')]);
            }

            if (WasteEntry::query()->where('corrects_entry_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['entry' => __('Raden är redan rättad.')]);
            }

            $movements = StockMovement::query()->where('source_type', $entry->getMorphClass())->where('source_id', (string) $entry->id)->where('type', MovementType::Scrap->value)->get();
            $costs = UnitCosts::at($company);
            $today = CarbonImmutable::today();

            $correction = WasteEntry::create([
                'registered_on' => $today->toDateString(),
                'kind' => $entry->kind,
                'article_id' => $entry->article_id,
                'quantity_milli' => -$entry->quantity_milli,
                'location_id' => $entry->location_id,
                'reason' => $entry->reason,
                'recipient' => $entry->recipient,
                'note' => mb_substr(trim((string) $note) ?: __('Rättelse av rad :id', ['id' => $entry->id]), 0, 255),
                'corrects_entry_id' => $entry->id,
                'created_by' => $user->id,
            ]);

            $this->ledger->post($company, $user, MovementType::Return, array_values($movements->map(fn (StockMovement $movement): array => [
                'article_id' => $movement->article_id,
                'location_id' => $movement->location_id,
                'quantity_milli' => -$movement->quantity_milli,
                'unit_cost_ore' => $costs->of($movement->article_id)['ore'],
                'reason' => 'RATTELSE_SVINN',
            ])->all()), $today, $correction, __('Rättelse av svinn'));

            Audit::record('kitchen.waste_corrected', $correction, ['corrects_entry_id' => $entry->id], $company, $user);

            return $correction;
        }));
    }

    /** Plate waste (tallrikssvinn) weighed in kg: a measurement only. */
    public function plate(User $user, Company $company, string $kg, ?string $date = null, ?string $note = null): WasteMeasurement
    {
        $this->guard($user, $company, 'register');

        $milli = self::quantity($kg, 'kg');

        try {
            $day = CarbonImmutable::parse($date ?: CarbonImmutable::today()->toDateString())->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages(['date' => __('Datumet går inte att läsa.')]);
        }

        if ($day->isAfter(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['date' => __('Väg tallrikssvinnet när det har slängts.')]);
        }

        return CurrentCompany::run($company, function () use ($user, $company, $milli, $day, $note): WasteMeasurement {
            $measurement = WasteMeasurement::create([
                'measured_on' => $day->toDateString(),
                'kg_milli' => $milli,
                'note' => trim((string) $note) === '' ? null : mb_substr(trim((string) $note), 0, 255),
                'created_by' => $user->id,
            ]);

            Audit::record('kitchen.plate_waste_measured', $measurement, ['kg_milli' => $milli], $company, $user);

            return $measurement;
        });
    }

    /** @return array<int, int> stocked article => thousandths out */
    private function lines(Company $company, string $kind, Article $article, int $quantity, CarbonImmutable $date): array
    {
        if ($kind === WasteEntry::INGREDIENT) {
            if (! $article->is_stock_item) {
                throw ValidationException::withMessages(['article_id' => __(':name är inte lagerförd. Välj rätten om det är en rätt som slängs.', ['name' => $article->name])]);
            }

            return [$article->id => $quantity];
        }

        $exploded = RecipeStock::lines(StructureBook::for($company), $article, $quantity, $date);

        if ($exploded['lines'] === []) {
            throw ValidationException::withMessages(['article_id' => __('Inget i receptet för :name är lagerfört, så inget dras från lagret.', ['name' => $article->name])]);
        }

        return $exploded['lines'];
    }

    private function location(Company $company, mixed $locationId, Article $article): StockLocation
    {
        $id = $locationId === null || $locationId === '' ? (KitchenSettings::locationId($company) ?? $article->default_location_id) : (int) $locationId;

        if ($id === null) {
            throw ValidationException::withMessages(['location_id' => __('Välj lagerställe, eller ange kökets lagerställe under Kök → Inställningar.')]);
        }

        return StockLocation::query()->where('is_active', true)->find($id)
            ?? throw ValidationException::withMessages(['location_id' => __('Lagerstället finns inte eller är inaktivt.')]);
    }

    private static function quantity(string $text, string $field = 'quantity'): int
    {
        try {
            $milli = Quantity::parse($text);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Mängden går inte att läsa. Skriv till exempel 2 eller 1,5.')]);
        }

        if ($milli <= 0) {
            throw ValidationException::withMessages([$field => __('Mängden måste vara mer än noll.')]);
        }

        return $milli;
    }
}
