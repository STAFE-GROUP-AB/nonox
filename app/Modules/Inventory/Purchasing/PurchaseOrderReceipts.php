<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\StockReceiptLine;
use Illuminate\Support\Collection;

/**
 * Keeps a purchase order's open goods receipt in step with the order's
 * current version (ADR-071 §2). A line where nothing has arrived follows its
 * row: article, what remains, and the cost per stock unit; a line where
 * goods have arrived keeps its article (the row's article and unit are
 * locked then) and expects what it has received plus what remains. Rows the
 * receipt lacks are added, lines whose row is gone are dropped.
 *
 * Lock order, everywhere a purchase order and its receipts are both locked:
 * the open receipts first, then the order (as ReceiveStock does), so two
 * actions never wait on each other. Runs inside the company and a
 * transaction.
 */
final class PurchaseOrderReceipts
{
    /**
     * Locks and returns the order's open receipts. Call before locking the order.
     *
     * @return Collection<int, StockReceipt>
     */
    public static function lockOpen(int $orderId): Collection
    {
        return StockReceipt::query()->where('purchase_order_id', $orderId)
            ->whereIn('status', [StockReceipt::OPEN, StockReceipt::PARTIAL])
            ->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Before rows are deleted: drops their lines where nothing has arrived
     * from open receipts, and unlinks them from cancelled or finished ones.
     *
     * @param  list<int>  $rowIds
     */
    public static function releaseRows(array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        $lines = StockReceiptLine::query()->with('receipt:id,status')->whereIn('purchase_order_row_id', $rowIds)->get();

        foreach ($lines as $line) {
            if ($line->received_milli === 0 && $line->receipt?->isOpen()) {
                $line->delete();

                continue;
            }

            $line->update(['purchase_order_row_id' => null]);
        }
    }

    /** Brings the order's open receipt (at most one) in line with its rows. */
    public static function sync(PurchaseOrder $order): void
    {
        $receipt = StockReceipt::query()->where('purchase_order_id', $order->id)
            ->whereIn('status', [StockReceipt::OPEN, StockReceipt::PARTIAL])
            ->latest('id')->first();

        if ($receipt === null) {
            return;
        }

        $rows = $order->rows()->with('article:id,default_location_id')->get()->keyBy('id');
        $lines = $receipt->lines()->get();
        $covered = [];

        foreach ($lines as $line) {
            $row = $line->purchase_order_row_id === null ? null : $rows->get($line->purchase_order_row_id);

            if ($row === null) {
                if ($line->received_milli === 0) {
                    $line->delete();
                }

                continue;
            }

            $covered[] = $row->id;
            $expected = $line->received_milli + $row->remainingMilli();

            if ($expected === 0) {
                $line->delete();

                continue;
            }

            $swapped = $line->received_milli === 0 && $line->article_id !== $row->article_id;

            $line->update([
                'article_id' => $swapped ? $row->article_id : $line->article_id,
                // Another article goes where that article usually lies.
                'location_id' => $swapped ? $row->article?->default_location_id : $line->location_id,
                'ordered_milli' => $expected,
                'unit_cost_ore' => $row->stockUnitCostOre(),
            ]);
        }

        $position = (int) $lines->max('position') + 1;

        foreach ($rows as $row) {
            /** @var PurchaseOrderRow $row */
            if (in_array($row->id, $covered, true) || $row->remainingMilli() === 0) {
                continue;
            }

            StockReceiptLine::create([
                'stock_receipt_id' => $receipt->id,
                'purchase_order_row_id' => $row->id,
                'article_id' => $row->article_id,
                'location_id' => $row->article?->default_location_id,
                'ordered_milli' => $row->remainingMilli(),
                'unit_cost_ore' => $row->stockUnitCostOre(),
                'position' => $position++,
            ]);
        }

        $lines = $receipt->lines()->get();

        if ($lines->isEmpty()) {
            $receipt->update(['status' => StockReceipt::CANCELLED]);

            return;
        }

        $done = $lines->every(fn (StockReceiptLine $line): bool => $line->received_milli >= $line->ordered_milli);
        $any = $lines->contains(fn (StockReceiptLine $line): bool => $line->received_milli > 0);
        $receipt->update(['status' => $done ? StockReceipt::RECEIVED : ($any ? StockReceipt::PARTIAL : StockReceipt::OPEN)]);
    }
}
