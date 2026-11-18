<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\VoucherSource;
use App\Models\Account;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Computes a rolling forecast (ADR-081 gap 2 scope 5): the posted outcome for
 * every month up to and including the cut-off, and for every month after it
 * one of three plain rules per row, unless a person typed the month by hand
 * (an override, kept as it is). Nothing here writes, and nothing is ever
 * posted. Six queries at most, whatever the size of the ledger or the budget.
 *
 * Rows are the active budget's rows (account, cost centre, project), the rows
 * a person added and the rows the outcome needs. Each posted amount belongs
 * to exactly one row, the most specific one there is: the same account, cost
 * centre and project; else the account and cost centre; else the account and
 * project; else the account alone (made when missing). So nothing is counted
 * twice, and an amount on a cost centre the budget has no row for is counted
 * on the account's own row.
 *
 * - Budget: the month in the fiscal year's active budget, 0 on a row the
 *   budget does not have.
 * - Average: the average of the row's outcome in the last N months up to and
 *   including the cut-off, rounded half up to whole kronor. Only months inside
 *   this or the previous fiscal year count; with fewer of them the average is
 *   taken over those there are, and with none it is 0.
 * - Last year: the row's outcome in the same month twelve months earlier
 *   (only months up to the cut-off), changed by the growth percentage and
 *   rounded half up to whole kronor.
 *
 * The average and last year read the outcome without the year-end closing
 * entries (YearEndClosing: vouchers of source CLOSING whose subject is a
 * fiscal year, such as the year's tax, deferred tax, appropriations and
 * their reversals on the next year's first day): they belong to the year
 * end, not to a month that repeats. Monthly depreciation and accruals, also
 * CLOSING but with an asset or accrual as subject, stay in. The outcome
 * months themselves show everything posted.
 *
 * @phpstan-type ForecastRow array{key: string, account_id: int, cost_center_id: ?int, project_id: ?int, number: int, revenue: bool, method: ForecastMethod, own_method: bool, amounts: array<string, int>, overrides: array<string, true>}
 */
final class ForecastEngine
{
    /**
     * @param  string  $cutoff  'Y-m': the last month with outcome
     * @param  array<string, array<string, int>>  $overrides  row key => ['Y-m' => ledger öre] typed by hand
     * @param  list<string>  $extraRows  row keys a person added
     * @return array{rows: list<ForecastRow>, months: list<string>, budget_version_id: ?int, average_months: int, has_previous_year: bool}
     */
    public static function compute(FiscalYear $fiscalYear, string $cutoff, ForecastSettings $settings, array $overrides = [], array $extraRows = []): array
    {
        $months = BudgetMonths::of($fiscalYear);

        $budgetId = BudgetVersion::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('kind', BudgetKind::Budget->value)
            ->where('is_active', true)
            ->value('id');
        $budget = $budgetId === null ? [] : self::budget((int) $budgetId);

        $previous = BudgetActuals::previousYear($fiscalYear);
        [$outcome, $basisOutcome] = self::outcome($fiscalYear, $previous, $cutoff);

        $keys = array_fill_keys(array_keys($budget), true);

        foreach ([...$extraRows, ...array_keys($overrides), ...array_keys($settings->rowMethods)] as $key) {
            if (self::validKey($key)) {
                $keys[$key] = true;
            }
        }

        $actuals = [];
        $basis = [];

        foreach ($outcome as $source => $perMonth) {
            $key = self::rowFor((string) $source, $keys);
            $keys[$key] = true;

            foreach ($perMonth as $month => $amount) {
                $actuals[$key][$month] = ($actuals[$key][$month] ?? 0) + $amount;
                $basis[$key][$month] = ($basis[$key][$month] ?? 0) + ($basisOutcome[(string) $source][$month] ?? 0);
            }
        }

        $accountIds = array_unique(array_map(fn (string $key): int => (int) explode('-', $key)[0], array_keys($keys)));
        $numbers = $accountIds === [] ? [] : Account::query()->whereIn('id', $accountIds)->pluck('number', 'id')->all();

        $window = self::window($fiscalYear, $previous, $cutoff, $settings->averageMonths);

        $rows = [];

        foreach (array_keys($keys) as $key) {
            $number = (int) ($numbers[(int) explode('-', $key)[0]] ?? 0);

            if (! BudgetMonths::isBudgetable($number)) {
                continue;
            }

            [$accountId, $costCenterId, $projectId] = array_map(intval(...), explode('-', $key));
            $revenue = BudgetMonths::isRevenue($number);
            $method = $settings->methodFor($key, $revenue);
            $own = $actuals[$key] ?? [];
            $base = $basis[$key] ?? [];
            $average = self::average($base, $window);
            $amounts = [];
            $typed = [];

            foreach ($months as $month) {
                if ($month <= $cutoff) {
                    $amounts[$month] = $own[$month] ?? 0;

                    continue;
                }

                if (isset($overrides[$key][$month])) {
                    $amounts[$month] = $overrides[$key][$month];
                    $typed[$month] = true;

                    continue;
                }

                $amounts[$month] = match ($method) {
                    ForecastMethod::Budget => $budget[$key][$month] ?? 0,
                    ForecastMethod::Average => $average,
                    ForecastMethod::LastYear => BudgetMonths::adjusted($base[BudgetMonths::shift($month, -12)] ?? 0, $settings->growthBasisPoints),
                };
            }

            $rows[] = [
                'key' => $key,
                'account_id' => $accountId,
                'cost_center_id' => $costCenterId === 0 ? null : $costCenterId,
                'project_id' => $projectId === 0 ? null : $projectId,
                'number' => $number,
                'revenue' => $revenue,
                'method' => $method,
                'own_method' => isset($settings->rowMethods[$key]),
                'amounts' => $amounts,
                'overrides' => $typed,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['number'], (int) $a['cost_center_id'], (int) $a['project_id']]
            <=> [$b['number'], (int) $b['cost_center_id'], (int) $b['project_id']]);

        return ['rows' => $rows, 'months' => $months, 'budget_version_id' => $budgetId === null ? null : (int) $budgetId, 'average_months' => count($window), 'has_previous_year' => $previous !== null];
    }

    /**
     * The lines to store: every month that is not zero, and every month typed
     * by hand (also a typed zero, so that it stays when the forecast is
     * computed again).
     *
     * @param  list<ForecastRow>  $rows
     * @return list<array{account_id: int, cost_center_id: ?int, project_id: ?int, period: string, amount_ore: int, is_override: bool}>
     */
    public static function lines(array $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            foreach ($row['amounts'] as $month => $amount) {
                $override = isset($row['overrides'][$month]);

                if ($amount === 0 && ! $override) {
                    continue;
                }

                $lines[] = [
                    'account_id' => $row['account_id'],
                    'cost_center_id' => $row['cost_center_id'],
                    'project_id' => $row['project_id'],
                    'period' => "{$month}-01",
                    'amount_ore' => $amount,
                    'is_override' => $override,
                ];
            }
        }

        return $lines;
    }

    public static function key(int $accountId, ?int $costCenterId, ?int $projectId): string
    {
        return "{$accountId}-".($costCenterId ?? 0).'-'.($projectId ?? 0);
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[1-9]\d*-\d+-\d+$/', $key) === 1;
    }

    /**
     * The row a posted amount on (account, cost centre, project) belongs to.
     *
     * @param  string  $source  "account|cost centre|project", 0 for none
     * @param  array<string, true>  $keys
     */
    private static function rowFor(string $source, array $keys): string
    {
        [$account, $costCenter, $project] = array_map(intval(...), explode('|', $source));

        foreach (["{$account}-{$costCenter}-{$project}", "{$account}-{$costCenter}-0", "{$account}-0-{$project}"] as $candidate) {
            if (isset($keys[$candidate])) {
                return $candidate;
            }
        }

        return "{$account}-0-0";
    }

    /** "Snittet räknas på 3 månader." for the forecast pages. */
    public static function averageNote(int $months): string
    {
        return match (true) {
            $months <= 0 => __('Det finns inga månader i bokföringen att räkna snittet på, så snittet ger 0.'),
            $months === 1 => __('Snittet räknas på en månad.'),
            default => __('Snittet räknas på :count månader.', ['count' => $months]),
        };
    }

    /**
     * The months the average is taken over: the last N up to and including the
     * cut-off that lie inside this or the previous fiscal year.
     *
     * @return list<string>
     */
    public static function window(FiscalYear $fiscalYear, ?FiscalYear $previous, string $cutoff, int $months): array
    {
        $first = ($previous ?? $fiscalYear)->start_date->format('Y-m');

        return array_values(array_filter(
            array_map(fn (int $back): string => BudgetMonths::shift($cutoff, -$back), range(0, max(1, $months) - 1)),
            fn (string $month): bool => $month >= $first,
        ));
    }

    /**
     * The posted outcome of this and the previous fiscal year per "account|cost
     * centre|project" and month, up to and including the cut-off: everything,
     * and the basis for the methods (without the year-end closing entries).
     * One query per year.
     *
     * @return array{0: array<string, array<string, int>>, 1: array<string, array<string, int>>}
     */
    private static function outcome(FiscalYear $fiscalYear, ?FiscalYear $previous, string $cutoff): array
    {
        $outcome = [];
        $basis = [];

        foreach (array_filter([$previous, $fiscalYear]) as $year) {
            $rows = LedgerQuery::forYear($year)->rows()
                ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
                ->whereBetween('accounts.number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])
                ->groupBy('voucher_rows.account_id', 'voucher_rows.cost_center_id', 'voucher_rows.project_id')
                ->groupByRaw("to_char(vouchers.voucher_date, 'YYYY-MM')")
                ->select('voucher_rows.account_id', 'voucher_rows.cost_center_id', 'voucher_rows.project_id')
                ->selectRaw("to_char(vouchers.voucher_date, 'YYYY-MM') AS month, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount")
                ->selectRaw(
                    'SUM(CASE WHEN vouchers.source = ? AND vouchers.source_type = ? THEN voucher_rows.debit_ore - voucher_rows.credit_ore ELSE 0 END) AS year_end',
                    [VoucherSource::Closing->value, (new FiscalYear)->getMorphClass()],
                )
                ->toBase()
                ->get();

            foreach ($rows as $row) {
                $month = (string) $row->month;

                if ($month > $cutoff) {
                    continue;
                }

                $key = ((int) $row->account_id).'|'.((int) $row->cost_center_id).'|'.((int) $row->project_id);
                $outcome[$key][$month] = ($outcome[$key][$month] ?? 0) + (int) $row->amount;
                $basis[$key][$month] = ($basis[$key][$month] ?? 0) + (int) $row->amount - (int) $row->year_end;
            }
        }

        return [$outcome, $basis];
    }

    /**
     * The active budget per row key and month, in ledger sign.
     *
     * @return array<string, array<string, int>>
     */
    private static function budget(int $versionId): array
    {
        $budget = [];

        foreach (BudgetScope::lines($versionId)->toBase()->get(['account_id', 'cost_center_id', 'project_id', 'period', 'amount_ore']) as $line) {
            $key = self::key((int) $line->account_id, $line->cost_center_id === null ? null : (int) $line->cost_center_id, $line->project_id === null ? null : (int) $line->project_id);
            $budget[$key][substr((string) $line->period, 0, 7)] = (int) $line->amount_ore;
        }

        return $budget;
    }

    /**
     * @param  array<string, int>  $perMonth
     * @param  list<string>  $window
     */
    private static function average(array $perMonth, array $window): int
    {
        if ($window === []) {
            return 0;
        }

        $sum = array_sum(array_intersect_key($perMonth, array_flip($window)));

        return BigDecimal::of($sum)->dividedBy(count($window) * 100, 0, RoundingMode::HalfUp)->toInt() * 100;
    }
}
