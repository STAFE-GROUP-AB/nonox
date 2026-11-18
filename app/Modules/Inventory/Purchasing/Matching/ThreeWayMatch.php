<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Matching;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Models\PurchaseMatchAllocation;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockReceiptLine;
use App\Modules\Inventory\Purchasing\PurchaseOrderMatch;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * The three-way comparison per row (ADR-081 E11, principle 4: plain rules
 * anyone can recompute by hand).
 *
 * For every purchase order row that rows of the invoice are allocated to:
 *
 * - ordered = the row's quantity as typed, in the row's unit, and its value;
 * - received = for a stock row the goods receipts' quantity in the row's
 *   unit (the stock quantity × typed ÷ stock, half up), for a row without an
 *   article the whole row once its delivery is confirmed, else nothing;
 * - previously invoiced = what other registered invoices are allocated on
 *   the same row;
 * - invoiced = the allocated invoice rows' amounts and quantities;
 * - quantity difference = invoiced − (received − previously invoiced);
 * - expected = invoiced quantity × the order's price, half up, and the price
 *   difference = invoiced − expected.
 *
 * The row is outside when the quantity difference or the price difference
 * exceeds the company's tolerances (MatchTolerances), when nothing has been
 * received or confirmed, or when the order is cancelled. Every invoice row
 * not allocated to an order row is a row of its own: within the tolerance
 * when all such rows together are at most the absolute amount, else
 * UNMATCHED. Runs in a fixed number of queries.
 */
final class ThreeWayMatch
{
    /** @return list<MatchLine> */
    public static function compute(SupplierInvoice $invoice, MatchTolerances $tolerances): array
    {
        $orders = PurchaseOrder::query()
            ->whereIn('id', fn ($query) => $query->select('purchase_order_id')->from('purchase_order_invoices')->where('supplier_invoice_id', $invoice->id))
            ->with('rows')
            ->orderBy('number')
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $invoiceRows = SupplierInvoiceRow::query()->where('supplier_invoice_id', $invoice->id)->orderBy('position')->orderBy('id')->get();
        $allocations = PurchaseMatchAllocation::query()->where('supplier_invoice_id', $invoice->id)->get()->keyBy('supplier_invoice_row_id');

        /** @var Collection<int, PurchaseOrderRow> $rows */
        $rows = $orders->flatMap(fn (PurchaseOrder $order): Collection => $order->rows->each(fn (PurchaseOrderRow $row) => $row->setRelation('purchaseOrder', $order)))->keyBy('id');
        $rowIds = $rows->keys()->all();

        $previous = PurchaseMatchAllocation::query()
            ->join('supplier_invoices', 'supplier_invoices.id', '=', 'purchase_match_allocations.supplier_invoice_id')
            ->whereIn('purchase_match_allocations.purchase_order_row_id', $rowIds)
            ->where('purchase_match_allocations.supplier_invoice_id', '!=', $invoice->id)
            ->where('supplier_invoices.kind', SupplierInvoice::INVOICE)
            ->groupBy('purchase_match_allocations.purchase_order_row_id')
            ->selectRaw('purchase_match_allocations.purchase_order_row_id as row_id, sum(purchase_match_allocations.invoiced_milli) as milli')
            ->toBase()
            ->pluck('milli', 'row_id')
            ->map(fn (mixed $milli): int => (int) $milli)
            ->all();

        $receiptLines = StockReceiptLine::query()
            ->whereIn('purchase_order_row_id', $rowIds)
            ->where('received_milli', '>', 0)
            ->groupBy('purchase_order_row_id')
            ->selectRaw('purchase_order_row_id as row_id, max(id) as line_id')
            ->toBase()
            ->pluck('line_id', 'row_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return self::lines($rows, $invoiceRows, $allocations, $previous, $receiptLines, $tolerances);
    }

    /**
     * @param  Collection<int, PurchaseOrderRow>  $rows  the linked orders' rows by id, with purchaseOrder set
     * @param  Collection<int, SupplierInvoiceRow>  $invoiceRows
     * @param  Collection<int, PurchaseMatchAllocation>  $allocations  by invoice row id
     * @param  array<int, int>  $previous  thousandths invoiced on other invoices, by order row id
     * @param  array<int, int>  $receiptLines  the latest receipt line with goods, by order row id
     * @return list<MatchLine>
     */
    public static function lines(Collection $rows, Collection $invoiceRows, Collection $allocations, array $previous, array $receiptLines, MatchTolerances $tolerances): array
    {
        $lines = [];
        $byOrderRow = [];
        $unallocated = [];

        foreach ($invoiceRows as $invoiceRow) {
            $allocation = $allocations->get($invoiceRow->id);

            if ($allocation instanceof PurchaseMatchAllocation && $rows->has($allocation->purchase_order_row_id)) {
                $byOrderRow[$allocation->purchase_order_row_id][] = [$invoiceRow, $allocation];

                continue;
            }

            $unallocated[] = $invoiceRow;
        }

        foreach ($rows as $row) {
            if (! isset($byOrderRow[$row->id])) {
                continue;
            }

            $lines[] = self::orderRowLine($row, $byOrderRow[$row->id], $previous[$row->id] ?? 0, $receiptLines[$row->id] ?? null, $tolerances);
        }

        $unallocatedOre = array_sum(array_map(fn (SupplierInvoiceRow $row): int => abs($row->amount_ore->ore), $unallocated));
        $small = $unallocatedOre <= $tolerances->absoluteOre;

        foreach ($unallocated as $invoiceRow) {
            $amount = $invoiceRow->amount_ore->ore;

            $lines[] = new MatchLine(
                purchaseOrderRowId: null,
                invoiceRowIds: [$invoiceRow->id],
                stockReceiptLineId: null,
                orderedMilli: 0,
                receivedMilli: 0,
                previouslyInvoicedMilli: 0,
                invoicedMilli: 0,
                orderedOre: 0,
                receivedOre: 0,
                invoicedOre: $amount,
                expectedOre: 0,
                quantityDiffMilli: 0,
                priceDiffOre: $amount,
                tolerances: $tolerances,
                status: $small ? MatchStatus::Within : MatchStatus::Unmatched,
                reasons: [$small ? MatchReason::SmallWithoutOrderRow : MatchReason::NoOrderRow],
            );
        }

        return $lines;
    }

    /** @param  list<array{0: SupplierInvoiceRow, 1: PurchaseMatchAllocation}>  $allocated */
    private static function orderRowLine(PurchaseOrderRow $row, array $allocated, int $previousMilli, ?int $receiptLineId, MatchTolerances $tolerances): MatchLine
    {
        $ordered = self::orderedMilli($row);
        $received = self::receivedMilli($row, $ordered);
        $invoicedMilli = array_sum(array_map(fn (array $pair): int => $pair[1]->invoiced_milli, $allocated));
        $invoicedOre = array_sum(array_map(fn (array $pair): int => $pair[0]->amount_ore->ore, $allocated));
        $expected = BigDecimal::of($invoicedMilli)->multipliedBy($row->price_ore)->dividedBy(1000, 0, RoundingMode::HalfUp)->toInt();
        $quantityDiff = $invoicedMilli - ($received - $previousMilli);
        $priceDiff = $invoicedOre - $expected;
        $reasons = [];

        if ($row->purchaseOrder?->status === PurchaseOrderStatus::Cancelled) {
            $reasons[] = MatchReason::OrderCancelled;
        }

        if ($received === 0 && $invoicedMilli > 0) {
            $reasons[] = $row->isStock() ? MatchReason::NotReceived : MatchReason::NotConfirmed;
        } elseif ($quantityDiff > $tolerances->quantityAllowance($received)) {
            $reasons[] = MatchReason::QuantityOver;
        } elseif ($quantityDiff < 0) {
            $reasons[] = MatchReason::PartlyInvoiced;
        }

        if ($priceDiff > $tolerances->priceAllowance($expected)) {
            $reasons[] = MatchReason::PriceOver;
        } elseif ($priceDiff < 0) {
            $reasons[] = MatchReason::PriceUnder;
        }

        $outside = array_filter($reasons, fn (MatchReason $reason): bool => $reason->isDeviation()) !== [];

        return new MatchLine(
            purchaseOrderRowId: $row->id,
            invoiceRowIds: array_map(fn (array $pair): int => $pair[0]->id, $allocated),
            stockReceiptLineId: $row->isStock() ? $receiptLineId : null,
            orderedMilli: $ordered,
            receivedMilli: $received,
            previouslyInvoicedMilli: $previousMilli,
            invoicedMilli: $invoicedMilli,
            orderedOre: $row->valueOre(),
            receivedOre: PurchaseOrderMatch::receivedValueOre($row),
            invoicedOre: $invoicedOre,
            expectedOre: $expected,
            quantityDiffMilli: $quantityDiff,
            priceDiffOre: $priceDiff,
            tolerances: $tolerances,
            status: $outside ? MatchStatus::Outside : MatchStatus::Within,
            reasons: $reasons,
        );
    }

    /** The row's quantity as typed, in thousandths of the row's unit. */
    public static function orderedMilli(PurchaseOrderRow $row): int
    {
        return BigDecimal::of($row->entered_quantity)->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** What has arrived on the row, in thousandths of the row's unit. */
    public static function receivedMilli(PurchaseOrderRow $row, ?int $ordered = null): int
    {
        $ordered ??= self::orderedMilli($row);

        if (! $row->isStock()) {
            return $row->confirmed_at === null ? 0 : $ordered;
        }

        if ($row->received_milli === 0 || $row->quantity_milli === 0) {
            return 0;
        }

        return BigDecimal::of($ordered)->multipliedBy($row->received_milli)->dividedBy($row->quantity_milli, 0, RoundingMode::HalfUp)->toInt();
    }
}
