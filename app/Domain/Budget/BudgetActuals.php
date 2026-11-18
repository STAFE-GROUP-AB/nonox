<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Domain\Accounting\Reports\LedgerQuery;
use App\Models\FiscalYear;

/**
 * The posted outcome of the result accounts per month, read through the
 * ledger's own LedgerQuery (read-only; the budget never writes to the books).
 * One query, whatever the size of the ledger.
 */
final class BudgetActuals
{
    /**
     * Debit minus credit per account and month: [account id => ['Y-m' => öre]].
     * With $perDimension the key is "account|cost centre|project" (0 for none).
     *
     * @return array<int|string, array<string, int>>
     */
    public static function perMonth(FiscalYear $fiscalYear, ?int $costCenterId = null, ?int $projectId = null, bool $perDimension = false): array
    {
        $query = new LedgerQuery($fiscalYear, $fiscalYear->start_date, $fiscalYear->end_date, $costCenterId, $projectId);

        $rows = $query->rows()
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->whereBetween('accounts.number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])
            ->select('voucher_rows.account_id')
            ->groupBy('voucher_rows.account_id')
            ->groupByRaw("to_char(vouchers.voucher_date, 'YYYY-MM')")
            ->when($perDimension, fn ($builder) => $builder->groupBy('voucher_rows.cost_center_id', 'voucher_rows.project_id')
                ->addSelect('voucher_rows.cost_center_id', 'voucher_rows.project_id'))
            ->selectRaw("to_char(vouchers.voucher_date, 'YYYY-MM') AS month, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount")
            ->toBase()
            ->get();

        $actuals = [];

        foreach ($rows as $row) {
            $key = $perDimension
                ? "{$row->account_id}|".((int) $row->cost_center_id).'|'.((int) $row->project_id)
                : (int) $row->account_id;

            $actuals[$key][(string) $row->month] = ($actuals[$key][(string) $row->month] ?? 0) + (int) $row->amount;
        }

        return $actuals;
    }

    /** The fiscal year that ends the day before this one starts, if there is one. */
    public static function previousYear(FiscalYear $fiscalYear): ?FiscalYear
    {
        return FiscalYear::query()->whereDate('end_date', $fiscalYear->start_date->subDay()->toDateString())->first();
    }

    /**
     * Last year's outcome for one budget row: the row's account, and its cost
     * centre and project where the row has one (a row without one takes the
     * account's whole outcome). Keys are this year's months (same calendar
     * month, one year earlier).
     *
     * @param  array<int|string, array<string, int>>  $actuals  from perMonth(..., perDimension: true)
     * @param  list<string>  $months  this year's months
     * @return array<string, int>
     */
    public static function forRow(array $actuals, int $accountId, ?int $costCenterId, ?int $projectId, array $months): array
    {
        $values = array_fill_keys($months, 0);

        foreach ($actuals as $key => $perMonth) {
            [$account, $costCenter, $project] = array_map(intval(...), explode('|', (string) $key));

            if ($account !== $accountId) {
                continue;
            }

            if ($costCenterId !== null && $costCenter !== $costCenterId) {
                continue;
            }

            if ($projectId !== null && $project !== $projectId) {
                continue;
            }

            foreach ($months as $month) {
                $values[$month] += $perMonth[BudgetMonths::shift($month, -12)] ?? 0;
            }
        }

        return $values;
    }
}
