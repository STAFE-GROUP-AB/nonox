<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Models\SupplierInvoice;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * A supplier invoice beside the purchase orders it was matched with
 * (ADR-071 §2): what was ordered, what the goods receipts say arrived, and
 * the invoice's amount excluding VAT, with the differences in quantity and
 * price flagged for a person to judge. It never approves anything. Amounts
 * are compared in kronor; a purchase order is in SEK. Runs inside the
 * company, in a fixed number of queries.
 */
final class PurchaseOrderMatch
{
    /**
     * @param  Collection<int, PurchaseOrder>  $orders  the matched orders
     * @param  Collection<int, PurchaseOrder>  $candidates  sent orders of the supplier not yet matched
     * @param  list<string>  $flags
     */
    private function __construct(
        public readonly Collection $orders,
        public readonly Collection $candidates,
        public readonly int $orderedOre,
        public readonly int $receivedOre,
        public readonly int $invoiceNetOre,
        public readonly array $flags,
    ) {}

    public static function forInvoice(SupplierInvoice $invoice): self
    {
        $orders = PurchaseOrder::query()
            ->whereIn('id', fn ($query) => $query->select('purchase_order_id')->from('purchase_order_invoices')->where('supplier_invoice_id', $invoice->id))
            ->with(['rows.unit:id,code', 'rows.article:id,number,name,stock_unit_id', 'rows.article.stockUnit:id,code'])
            ->orderBy('number')
            ->get();

        $candidates = PurchaseOrder::query()
            ->where('supplier_id', $invoice->supplier_id)
            ->whereNotNull('sent_at')
            ->where('status', '!=', PurchaseOrderStatus::Cancelled)
            ->whereNotIn('id', $orders->pluck('id')->all())
            ->latest('number')
            ->limit(20)
            ->get(['id', 'number', 'order_date', 'status']);

        $ordered = (int) $orders->sum(fn (PurchaseOrder $order): int => $order->totalOre());
        $received = (int) $orders->sum(fn (PurchaseOrder $order): int => (int) $order->rows->sum(fn (PurchaseOrderRow $row): int => self::receivedValueOre($row)));
        $net = $invoice->total_ore->ore - $invoice->vat_ore->ore;
        $flags = [];

        if ($orders->isNotEmpty()) {
            foreach ($orders as $order) {
                foreach ($order->rows as $row) {
                    if ($row->received_milli !== $row->quantity_milli) {
                        $unit = $row->article?->stockUnitCode() ?? '';
                        $flags[] = __(':order, :name: beställt :ordered :unit, mottaget :received :unit.', [
                            'order' => $order->title(),
                            'name' => $row->description,
                            'ordered' => Quantity::format($row->quantity_milli),
                            'received' => Quantity::format($row->received_milli),
                            'unit' => $unit,
                        ]);
                    }
                }
            }

            if ($net !== $received) {
                $flags[] = $net > $received
                    ? __('Fakturan är :amount högre än värdet av det som har tagits emot.', ['amount' => Money::ofOre($net - $received)->format()])
                    : __('Fakturan är :amount lägre än värdet av det som har tagits emot.', ['amount' => Money::ofOre($received - $net)->format()]);
            }

            if ($invoice->invoice_currency !== 'SEK') {
                $flags[] = __('Fakturan är i :currency. Jämförelsen görs i kronor med fakturans bokförda belopp.', ['currency' => $invoice->invoice_currency]);
            }
        }

        return new self($orders, $candidates, $ordered, $received, $net, $flags);
    }

    /** What has arrived on a row is worth its share of the row's value, half up. */
    public static function receivedValueOre(PurchaseOrderRow $row): int
    {
        if ($row->received_milli === 0) {
            return 0;
        }

        return BigDecimal::of($row->valueOre())->multipliedBy($row->received_milli)->dividedBy($row->quantity_milli, 0, RoundingMode::HalfUp)->toInt();
    }
}
