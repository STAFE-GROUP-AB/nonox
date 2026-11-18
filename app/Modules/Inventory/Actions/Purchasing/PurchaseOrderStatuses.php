<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderRow;

/**
 * The status a sent purchase order has from what its rows have received
 * (ADR-071 §2); a row without an article counts when its delivery is confirmed (ADR-081 E10). A draft (also a sent order being changed into its next
 * version) and a cancelled order keep their status.
 */
final class PurchaseOrderStatuses
{
    public static function refresh(PurchaseOrder $order, bool $afterSending = false): void
    {
        if ($order->status === PurchaseOrderStatus::Cancelled || ($order->isDraft() && ! $afterSending)) {
            return;
        }

        $rows = $order->rows()->get();
        $status = match (true) {
            $rows->every(fn (PurchaseOrderRow $row): bool => $row->isFulfilled()) => PurchaseOrderStatus::Received,
            $rows->contains(fn (PurchaseOrderRow $row): bool => $row->hasDelivery()) => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Sent,
        };

        if ($order->status !== $status) {
            $order->update(['status' => $status]);
        }
    }
}
