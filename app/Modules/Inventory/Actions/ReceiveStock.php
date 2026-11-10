<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Concerns\GuardsInventory;
use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderStatuses;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Modules\Inventory\Support\StockLedger;
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
     * @param  array<int|string, array{quantity?: mixed, location_id?: mixed}>  $receive  line id => what arrived; omitted lines receive nothing
     */
    public function __invoke(User $user, Company $company, StockReceipt $receipt, array $receive, CarbonImmutable $date): StockReceipt
    {
        $this->guard($user, $company, 'count');

        return CurrentCompany::run($company, fn (): StockReceipt => DB::transaction(function () use ($user, $company, $receipt, $receive, $date): StockReceipt {
            $receipt = StockReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();

            if (! $receipt->isOpen()) {
                throw ValidationException::withMessages(['receipt' => __('Inleveransen är redan klar eller makulerad.')]);
            }

            $lines = $receipt->lines()->get()->keyBy('id');
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

                $movements[] = ['article_id' => $line->article_id, 'location_id' => $locationId, 'quantity_milli' => $quantity, 'unit_cost_ore' => $line->unit_cost_ore];
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

    /** Receives what remains on every line, at each line's location. */
    public function all(User $user, Company $company, StockReceipt $receipt, CarbonImmutable $date): StockReceipt
    {
        $receive = [];

        foreach ($receipt->lines()->get() as $line) {
            if ($line->remainingMilli() > 0) {
                $receive[$line->id] = ['quantity' => Quantity::decimal($line->remainingMilli())];
            }
        }

        return $this($user, $company, $receipt, $receive, $date);
    }
}
