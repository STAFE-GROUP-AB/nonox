<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Domain\Signing\SigningStatus;
use App\Models\SigningRequest;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A sent ÄTA whose signing links have all run out without a signature or an
 * answer becomes EXPIRED (review P3-9). Written by the scheduled command
 * `work-orders:expire-change-orders`, per company; the pages only read
 * (lapsed()) to show the label in the meantime.
 */
final class ExpireChangeOrders
{
    /** Marks the company's lapsed sent ÄTA as expired; the number marked. */
    public static function forCompany(int $companyId): int
    {
        return CurrentCompany::run($companyId, function (): int {
            $sent = ChangeOrder::query()->where('status', ChangeOrderStatus::Sent->value)->get();
            $lapsed = self::lapsedIn($sent);

            foreach ($sent->whereIn('id', $lapsed) as $changeOrder) {
                DB::transaction(function () use ($changeOrder): void {
                    $locked = ChangeOrder::query()->whereKey($changeOrder->id)->lockForUpdate()->first();

                    if ($locked === null || $locked->status !== ChangeOrderStatus::Sent) {
                        return;
                    }

                    $locked->forceFill(['status' => ChangeOrderStatus::Expired, 'decided_at' => CarbonImmutable::now()])->save();
                    Audit::record('work_orders.change_order_decided', $locked, ['work_order_id' => $locked->work_order_id, 'status' => ChangeOrderStatus::Expired->value]);
                });
            }

            return count($lapsed);
        });
    }

    /**
     * The ids of the sent ÄTA among these whose links have all run out:
     * read only, one query, for the label on the pages.
     *
     * @param  Collection<int, ChangeOrder>  $changeOrders
     * @return array<int, int>
     */
    public static function lapsedIn(Collection $changeOrders): array
    {
        $sent = $changeOrders->filter(fn (ChangeOrder $changeOrder): bool => $changeOrder->status === ChangeOrderStatus::Sent);

        if ($sent->isEmpty()) {
            return [];
        }

        $live = SigningRequest::query()
            ->where('document_type', ChangeOrder::SIGNING_TYPE)
            ->whereIn('signable_id', $sent->map(fn (ChangeOrder $changeOrder): string => (string) $changeOrder->id)->values()->all())
            ->where('status', SigningStatus::Pending->value)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->pluck('signable_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $sent->pluck('id')->map(fn (mixed $id): int => (int) $id)->reject(fn (int $id): bool => in_array($id, $live, true))->values()->all();
    }
}
