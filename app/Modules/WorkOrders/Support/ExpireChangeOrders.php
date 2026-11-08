<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Domain\Signing\SigningStatus;
use App\Models\SigningRequest;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A sent ÄTA whose signing links have all run out without a signature or an
 * answer becomes EXPIRED (review P3-9). Done when the work order is read:
 * one query for the sent ÄTA and one for their links, whatever their number.
 */
final class ExpireChangeOrders
{
    public static function forWorkOrder(int $workOrderId): void
    {
        $sent = ChangeOrder::query()->where('work_order_id', $workOrderId)->where('status', ChangeOrderStatus::Sent->value)->get();

        if ($sent->isEmpty()) {
            return;
        }

        $live = SigningRequest::query()
            ->where('document_type', ChangeOrder::SIGNING_TYPE)
            ->whereIn('signable_id', $sent->map(fn (ChangeOrder $changeOrder): string => (string) $changeOrder->id)->all())
            ->where('status', SigningStatus::Pending->value)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->pluck('signable_id')
            ->all();

        foreach ($sent as $changeOrder) {
            if (in_array((string) $changeOrder->id, $live, true)) {
                continue;
            }

            DB::transaction(function () use ($changeOrder): void {
                $locked = ChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== ChangeOrderStatus::Sent) {
                    return;
                }

                $locked->forceFill(['status' => ChangeOrderStatus::Expired, 'decided_at' => CarbonImmutable::now()])->save();
                Audit::record('work_orders.change_order_decided', $locked, ['work_order_id' => $locked->work_order_id, 'status' => ChangeOrderStatus::Expired->value]);
            });
        }
    }
}
