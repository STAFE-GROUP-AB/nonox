<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Support;

use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Invoice;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Live billable entries that no issued invoice covers yet: neither
 * reversed nor a reversal, and not on an issued invoice (a draft only
 * reserves them).
 */
final class Uninvoiced
{
    /**
     * @template T of TimeEntry|MaterialEntry
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function live(Builder $query): Builder
    {
        return $query->whereNull('reverses_id')->whereNull('reversed_by_id');
    }

    /**
     * @template T of TimeEntry|MaterialEntry
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function open(Builder $query): Builder
    {
        return self::live($query)
            ->where('billable', true)
            ->where(fn (Builder $inner) => $inner->whereNull('invoice_id')
                ->orWhereIn('invoice_id', Invoice::query()->select('id')->where('status', InvoiceStatus::Draft->value)));
    }

    /**
     * @template T of TimeEntry|MaterialEntry
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function free(Builder $query): Builder
    {
        return self::live($query)->where('billable', true)->whereNull('invoice_id');
    }

    public static function count(WorkOrder $workOrder): int
    {
        return self::open(TimeEntry::query()->where('work_order_id', $workOrder->id))->count()
            + self::open(MaterialEntry::query()->where('work_order_id', $workOrder->id))->count();
    }
}
