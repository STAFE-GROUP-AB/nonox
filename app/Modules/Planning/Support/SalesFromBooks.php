<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Domain\Accounting\VoucherStatus;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;

/**
 * The sales per day from the books (PLANNING_MODULE.md §3, §7): credit less
 * debit on the company's revenue accounts in posted vouchers, by voucher
 * date. One query for the whole period, in the current company.
 *
 * Bemanningsbehov (ADR-037 gap 1) also reads a few single days outside the
 * period (last year's holidays) and, for an area with a cost centre, only
 * the rows on that cost centre. Per day is the finest honest resolution: a
 * voucher has a date, not a time.
 */
final class SalesFromBooks
{
    /**
     * @param  list<string>  $alsoDates  Y-m-d outside the period
     * @return array<string, int> Y-m-d => öre, only days with sales
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

    /**
     * The same figures for several companies at once, each on its own
     * revenue accounts, per day and per cost centre, in one query (the
     * staff-cost budget, ADR-037 gap 2, and the chain view). The caller
     * decides the companies: across companies it runs inside
     * CompanyScope::bypass with ids from the user's own memberships.
     *
     * @param  array<int, array{int, int}>  $accounts  company id => [account from, account to]
     * @param  list<string>  $alsoDates  Y-m-d outside the period
     * @return array<int, array{all: array<string, int>, centres: array<int, array<string, int>>}> company id => the whole company's days and each cost centre's
     */
    public static function perCompanyDay(array $accounts, CarbonImmutable $from, CarbonImmutable $to, array $alsoDates = []): array
    {
        if ($accounts === []) {
            return [];
        }

        $rows = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('voucher_rows.company_id', array_keys($accounts))
            ->where(function ($query) use ($from, $to, $alsoDates): void {
                $query->whereBetween('vouchers.voucher_date', [$from->toDateString(), $to->toDateString()]);

                if ($alsoDates !== []) {
                    $query->orWhereIn('vouchers.voucher_date', $alsoDates);
                }
            })
            ->where(function ($query) use ($accounts): void {
                foreach ($accounts as $companyId => [$accountFrom, $accountTo]) {
                    $query->orWhere(fn ($query) => $query->where('voucher_rows.company_id', $companyId)
                        ->whereBetween('accounts.number', [min($accountFrom, $accountTo), max($accountFrom, $accountTo)]));
                }
            })
            ->groupBy('voucher_rows.company_id', 'vouchers.voucher_date', 'voucher_rows.cost_center_id')
            ->selectRaw('voucher_rows.company_id AS company, vouchers.voucher_date::text AS day, voucher_rows.cost_center_id AS centre, SUM(voucher_rows.credit_ore - voucher_rows.debit_ore) AS sales')
            ->toBase()
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $company = (int) $row->company;
            $day = substr((string) $row->day, 0, 10);
            $sales = (int) $row->sales;
            $result[$company] ??= ['all' => [], 'centres' => []];
            $result[$company]['all'][$day] = ($result[$company]['all'][$day] ?? 0) + $sales;

            if ($row->centre !== null) {
                $centre = (int) $row->centre;
                $result[$company]['centres'][$centre][$day] = ($result[$company]['centres'][$centre][$day] ?? 0) + $sales;
            }
        }

        return $result;
    }
}
