<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\Models\PurchaseOrderRow;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use App\Support\Audit\Audit;

/**
 * The link between a purchase requisition's rows and the purchase order rows
 * they were converted into (ADR-081 E9). When an order row goes away (the order
 * is changed while a draft, or cancelled) the requisition row is released and
 * can be converted again; a requisition that was converted as a whole goes back
 * to approved. Runs inside the company and a transaction.
 */
final class PurchaseRequisitionLinks
{
    /**
     * @param  array<int, int>  $orderRowIds
     */
    public static function release(array $orderRowIds): void
    {
        if ($orderRowIds === []) {
            return;
        }

        $rows = PurchaseRequisitionRow::query()->whereIn('purchase_order_row_id', $orderRowIds)->get();

        if ($rows->isEmpty()) {
            return;
        }

        $requisitions = PurchaseRequisition::query()
            ->whereKey($rows->pluck('purchase_requisition_id')->unique()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        PurchaseRequisitionRow::query()->whereKey($rows->modelKeys())->update(['purchase_order_row_id' => null]);

        foreach ($requisitions as $requisition) {
            if ($requisition->status !== PurchaseRequisitionStatus::Converted) {
                continue;
            }

            $requisition->forceFill(['status' => PurchaseRequisitionStatus::Approved])->save();
            Audit::record('inventory.purchase_requisition_released', $requisition, ['number' => $requisition->number], $requisition->company_id);
        }
    }

    /** Every purchase order row of the order that came from a requisition goes free. */
    public static function releaseOrder(int $orderId): void
    {
        self::release(PurchaseOrderRow::query()->where('purchase_order_id', $orderId)->pluck('id')->all());
    }
}
