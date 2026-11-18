<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderStatuses;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\Tracking;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockLots;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Receives goods on an inleverans, all of it or a part: posts a RECEIPT
 * movement per line at the line's cost and location, adds to what is
 * received, and marks the receipt partly or fully received; a receipt for a
 * purchase order ticks off the order's rows. Nothing is booked in the
 * ledger (ADR-039, ADR-071 §2).
 */
class ReceiveStock
{
    use GuardsInventory;

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array<int|string, array{quantity?: mixed, location_id?: mixed, lot_code?: mixed, expires_on?: mixed, serials?: mixed}>  $receive  line id => what arrived; omitted lines receive nothing
     */
    public function __invoke(User $user, Company $company, StockReceipt $receipt, array $receive, CarbonImmutable $date): StockReceipt
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockReceipt => DB::transaction(function () use ($user, $company, $receipt, $receive, $date): StockReceipt {
            $receipt = StockReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if (! $receipt->isOpen()) {
                throw ValidationException::withMessages(['receipt' => __('Inleveransen är redan klar eller makulerad.')]);
            }

            $lines = $receipt->lines()->with('article:id,number,unit,tracking,expiry_required')->get()->keyBy('id');
            $movements = [];
            $updates = [];

            foreach ($receive as $lineId => $row) {
                $line = $lines->get((int) $lineId) ?? throw ValidationException::withMessages(['lines' => __('Raden hör inte till inleveransen.')]);

                try {
                    $quantity = Quantity::parse((string) ($row['quantity'] ?? '0'));
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(["receive.{$lineId}.quantity" => __('Antalet går inte att läsa. Skriv till exempel 10 eller 2,5.')]);
                }

                if ($quantity === 0) {
                    continue;
                }

                if ($quantity < 0) {
                    throw ValidationException::withMessages(["receive.{$lineId}.quantity" => __('Antalet kan inte vara negativt. Använd retur för varor tillbaka till leverantören.')]);
                }

                $locationId = ($row['location_id'] ?? null) ? (int) $row['location_id'] : $line->location_id;

                if ($locationId === null) {
                    throw ValidationException::withMessages(["receive.{$lineId}.location_id" => __('Välj var varorna ska ligga.')]);
                }

                $movement = ['article_id' => $line->article_id, 'location_id' => $locationId, 'quantity_milli' => $quantity, 'unit_cost_ore' => $line->unit_cost_ore];

                if ($line->article?->tracking->isTracked()) {
                    $movement = [...$movement, ...$this->lotFields($line, $row, $receipt)];
                }

                $movements[] = $movement;
                $updates[] = [$line, $quantity, $locationId];
            }

            if ($movements === []) {
                throw ValidationException::withMessages(['receive' => __('Ange hur mycket som har kommit.')]);
            }

            $this->ledger->post($company, $user, MovementType::Receipt, $movements, $date, $receipt, __('Inleverans :number', ['number' => $receipt->number]));

            foreach ($updates as [$line, $quantity, $locationId]) {
                /** @var StockReceiptLine $line */
                $line->update(['received_milli' => $line->received_milli + $quantity, 'location_id' => $line->location_id ?? $locationId]);
            }

            $this->tickOffPurchaseOrder($receipt, $updates);

            $done = $lines->every(fn (StockReceiptLine $line): bool => $line->received_milli >= $line->ordered_milli);
            $receipt->update(['status' => $done ? StockReceipt::RECEIVED : StockReceipt::PARTIAL]);

            return $receipt;
        }));
    }

    /**
     * The batch or serial numbers of what arrived on a line of a tracked
     * article (ADR-081 E12): a batch code and best-before date, or the serial
     * numbers one per line. The lot remembers the supplier and the line.
     *
     * @param  array{quantity?: mixed, location_id?: mixed, lot_code?: mixed, expires_on?: mixed, serials?: mixed}  $row
     * @return array{supplier_id: ?int, stock_receipt_line_id: int, expires_on?: string, lot_codes?: list<string>, lot_code?: string}
     */
    private function lotFields(StockReceiptLine $line, array $row, StockReceipt $receipt): array
    {
        $fields = ['supplier_id' => $receipt->supplier_id, 'stock_receipt_line_id' => $line->id];
        $expires = trim((string) ($row['expires_on'] ?? ''));

        if ($expires !== '') {
            $fields['expires_on'] = $expires;
        }

        if ($line->article?->tracking === Tracking::Serial) {
            return [...$fields, 'lot_codes' => StockLots::codes((string) ($row['serials'] ?? ''))];
        }

        return [...$fields, 'lot_code' => trim((string) ($row['lot_code'] ?? ''))];
    }

    /**
     * A receipt made from a purchase order adds what arrived to the order's
     * rows and moves the order to partly or fully received (ADR-071 §2).
     *
     * @param  list<array{0: StockReceiptLine, 1: int, 2: int}>  $updates
     */
    private function tickOffPurchaseOrder(StockReceipt $receipt, array $updates): void
    {
        if ($receipt->purchase_order_id === null) {
            return;
        }

        $order = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->first();

        if ($order === null) {
            return;
        }

        foreach ($updates as [$line, $quantity]) {
            if ($line->purchase_order_row_id !== null) {
                PurchaseOrderRow::query()->whereKey($line->purchase_order_row_id)->where('purchase_order_id', $order->id)->increment('received_milli', $quantity);
            }
        }

        PurchaseOrderStatuses::refresh($order);
    }

    /**
     * Receives what remains on every line, at each line's location.
     *
     * @param  array<int, array{lot_code?: string, expires_on?: string, serials?: string}>  $lots  batch or serial numbers per line, by the line's order (a tracked article)
     */
    public function all(User $user, Company $company, StockReceipt $receipt, CarbonImmutable $date, array $lots = []): StockReceipt
    {
        $receive = [];

        foreach ($receipt->lines()->orderBy('position')->orderBy('id')->get()->values() as $index => $line) {
            if ($line->remainingMilli() > 0) {
                $receive[$line->id] = ['quantity' => Quantity::decimal($line->remainingMilli()), ...($lots[$index] ?? [])];
            }
        }

        return $this($user, $company, $receipt, $receive, $date);
    }
}
