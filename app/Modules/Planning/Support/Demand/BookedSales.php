<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Demand;

use App\Domain\Accounting\VoucherStatus;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;

/**
 * Sales per day from the books for the demand forecast: credit less debit
 * on the revenue accounts in posted vouchers, by voucher date, for a period
 * and a few single days (last year's holidays), optionally on one cost
 * centre (an area's, see AreaCostCentre). Per day is the finest honest
 * resolution: a voucher has a date, not a time. One query, current company.
 */
final class BookedSales
{
    /**
     * @param  list<string>  $alsoDates  Y-m-d outside the period
     * @return array<string, int> Y-m-d => öre, only days with rows
     */
    public static function perDay(CarbonImmutable $from, CarbonImmutable $to, int $accountFrom, int $accountTo, ?int $costCenterId = null, array $alsoDates = []): array
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->where(function ($query) use ($from, $to, $alsoDates): void {
                $query->whereBetween('vouchers.voucher_date', [$from->toDateString(), $to->toDateString()]);

                if ($alsoDates !== []) {
                    $query->orWhereIn('vouchers.voucher_date', $alsoDates);
                }
            })
            ->whereBetween('accounts.number', [min($accountFrom, $accountTo), max($accountFrom, $accountTo)])
            ->when($costCenterId !== null, fn ($query) => $query->where('voucher_rows.cost_center_id', $costCenterId))
            ->groupBy('vouchers.voucher_date')
            ->selectRaw('vouchers.voucher_date::text AS day, SUM(voucher_rows.credit_ore - voucher_rows.debit_ore) AS sales')
            ->toBase()
            ->pluck('sales', 'day')
            ->mapWithKeys(fn (mixed $sales, mixed $day): array => [substr((string) $day, 0, 10) => (int) $sales])
            ->all();
    }
}
