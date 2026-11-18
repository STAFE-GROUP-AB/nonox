<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockLots;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A single movement by hand: move between locations (FLYTT), scrap
 * (KASSATION), or a return from a customer (in) or to a supplier (out).
 * Receipts, picks and counts have their own actions.
 */
class RecordMovement
{
    use GuardsInventory;

    public const array KINDS = ['transfer', 'scrap', 'return_in', 'return_out'];

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $input  kind, article_id, location_id, to_location_id (transfer), quantity, date, note, lot_id, lot_code, expires_on, serials (a tracked article)
     * @return list<StockMovement>
     */
    public function __invoke(User $user, Company $company, array $input): array
    {
        $this->guard($user, $company, 'count');

        $validated = Validator::make($input, [
            'kind' => ['required', 'in:'.implode(',', self::KINDS)],
            'article_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'],
            'to_location_id' => ['nullable', 'required_if:kind,transfer', 'integer'],
            'quantity' => ['required'],
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
            'lot_id' => ['nullable', 'integer'],
            'lot_code' => ['nullable', 'string', 'max:60'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'serials' => ['nullable', 'string', 'max:5000'],
        ], [
            'to_location_id.required_if' => __('Välj vart varorna flyttas.'),
        ], [
            'article_id' => __('artikel'),
            'location_id' => __('lagerställe'),
            'quantity' => __('antal'),
        ])->validate();

        try {
            $quantity = Quantity::parse((string) $validated['quantity']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Antalet måste vara större än noll.')]);
        }

        $date = CarbonImmutable::parse((string) $validated['date']);
        $note = $validated['note'] ?? null;
        $line = ['article_id' => (int) $validated['article_id'], 'location_id' => (int) $validated['location_id']];
        $lotId = ($validated['lot_id'] ?? null) === null || $validated['lot_id'] === '' ? null : (int) $validated['lot_id'];

        // A tracked article's goods come back under a batch or serial numbers (ADR-081 E12); what goes out is taken by FEFO unless a lot is named.
        $incoming = $validated['kind'] === 'return_in';
        $lot = ['lot_id' => $lotId];

        if ($incoming && $lotId === null) {
            $serials = StockLots::codes((string) ($validated['serials'] ?? ''));
            $lot = $serials !== []
                ? ['lot_codes' => $serials]
                : ['lot_code' => trim((string) ($validated['lot_code'] ?? '')) ?: null, 'expires_on' => ($validated['expires_on'] ?? null) ?: null];
        }

        $lot = array_filter($lot, fn (mixed $value): bool => $value !== null);

        return match ($validated['kind']) {
            'transfer' => $this->ledger->transfer($company, $user, $line['article_id'], $line['location_id'], (int) $validated['to_location_id'], $quantity, $date, $note, $lotId),
            'scrap' => $this->ledger->post($company, $user, MovementType::Scrap, [[...$line, ...$lot, 'quantity_milli' => -$quantity]], $date, null, $note),
            'return_in' => $this->ledger->post($company, $user, MovementType::Return, [[...$line, ...$lot, 'quantity_milli' => $quantity]], $date, null, $note),
            default => $this->ledger->post($company, $user, MovementType::Return, [[...$line, ...$lot, 'quantity_milli' => -$quantity]], $date, null, $note),
        };
    }
}
