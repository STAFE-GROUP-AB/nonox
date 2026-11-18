<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Domain\Accounting\Reports\LedgerReports;
use App\Models\Account;
use App\Models\BudgetVersion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Actual against budget for the income statement (ADR-081 gap 2 scope 4): for
 * one month and for the year to date, per account in the income statement's
 * groups and subtotals. Amounts are in the income statement's sign (revenue
 * positive, costs negative), so a positive difference is always better than
 * the budget. Four queries, whatever the size of the ledger or the budget.
 */
final class BudgetComparison
{
    /**
     * @param  string  $month  'Y-m', inside the version's fiscal year
     * @return array{
     *     months: list<string>,
     *     month: string,
     *     groups: list<array{key: string, label: string, rows: list<array{number: int, name: string, month: array{actual: int, budget: int, difference: int, percent: ?int}, ytd: array{actual: int, budget: int, difference: int, percent: ?int}, year_budget: int}>, total: array{month: array{actual: int, budget: int, difference: int, percent: ?int}, ytd: array{actual: int, budget: int, difference: int, percent: ?int}, year_budget: int}, subtotal: ?array{label: string, month: array{actual: int, budget: int, difference: int, percent: ?int}, ytd: array{actual: int, budget: int, difference: int, percent: ?int}, year_budget: int}}>,
     *     result: array{month: array{actual: int, budget: int, difference: int, percent: ?int}, ytd: array{actual: int, budget: int, difference: int, percent: ?int}, year_budget: int}
     * }
     */
    public static function build(BudgetVersion $version, string $month, ?int $costCenterId = null, ?int $projectId = null): array
    {
        $fiscalYear = $version->loadMissing('fiscalYear')->fiscalYear;
        $months = BudgetMonths::of($fiscalYear);
        $month = in_array($month, $months, true) ? $month : ($months[count($months) - 1] ?? $month);
        $ytdMonths = array_slice($months, 0, (int) array_search($month, $months, true) + 1);

        $actuals = BudgetActuals::perMonth($fiscalYear, $costCenterId, $projectId);
        $budget = self::budgetPerMonth($version, $costCenterId, $projectId);

        $accounts = Account::query()
            ->whereBetween('number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])
            ->whereIn('id', array_unique([...array_keys($actuals), ...array_keys($budget)]))
            ->orderBy('number')
            ->get(['id', 'number', 'name']);

        $groups = [];
        $sums = [];

        foreach (LedgerReports::INCOME_GROUPS as $group) {
            $rows = [];

            foreach ($accounts->filter(fn (Account $account): bool => $account->number >= $group['from'] && $account->number <= $group['to']) as $account) {
                $actual = $actuals[$account->id] ?? [];
                $planned = $budget[$account->id] ?? [];

                $rows[] = [
                    'number' => $account->number,
                    'name' => $account->name,
                    ...self::figures(
                        -($actual[$month] ?? 0),
                        -($planned[$month] ?? 0),
                        -self::sum($actual, $ytdMonths),
                        -self::sum($planned, $ytdMonths),
                        -self::sum($planned, $months),
                    ),
                ];
            }

            $sums[$group['key']] = self::total($rows);
            $subtotal = LedgerReports::INCOME_SUBTOTALS[$group['key']] ?? null;

            $groups[] = [
                'key' => $group['key'],
                'label' => __($group['label']),
                'rows' => $rows,
                'total' => $sums[$group['key']],
                'subtotal' => $subtotal === null ? null : [
                    'label' => __($subtotal['label']),
                    ...self::total(array_values(array_intersect_key($sums, array_flip($subtotal['groups'])))),
                ],
            ];
        }

        return [
            'months' => $months,
            'month' => $month,
            'groups' => array_values(array_filter($groups, fn (array $group): bool => $group['rows'] !== [])),
            'result' => self::total(array_values($sums)),
        ];
    }

    /**
     * Difference in percent of the budget, in basis points (1234 = 12,34 %);
     * null when the budget is zero.
     */
    public static function percent(int $difference, int $budget): ?int
    {
        if ($budget === 0) {
            return null;
        }

        return BigDecimal::of($difference)->multipliedBy(10000)->dividedBy(abs($budget), 0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * Budget per account and month in ledger sign, summed over cost centres
     * and projects unless one is chosen.
     *
     * @return array<int, array<string, int>>
     */
    private static function budgetPerMonth(BudgetVersion $version, ?int $costCenterId, ?int $projectId): array
    {
        $rows = BudgetScope::lines($version->id)
            ->when($costCenterId !== null, fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->groupBy('account_id', 'period')
            ->select('account_id')
            ->selectRaw("to_char(period, 'YYYY-MM') AS month, SUM(amount_ore) AS amount")
            ->toBase()
            ->get();

        $budget = [];

        foreach ($rows as $row) {
            $budget[(int) $row->account_id][(string) $row->month] = (int) $row->amount;
        }

        return $budget;
    }

    /**
     * @param  array<string, int>  $perMonth
     * @param  list<string>  $months
     */
    private static function sum(array $perMonth, array $months): int
    {
        return array_sum(array_intersect_key($perMonth, array_flip($months)));
    }

    /** @return array{month: array{actual: int, budget: int, difference: int, percent: ?int}, ytd: array{actual: int, budget: int, difference: int, percent: ?int}, year_budget: int} */
    private static function figures(int $monthActual, int $monthBudget, int $ytdActual, int $ytdBudget, int $yearBudget): array
    {
        return [
            'month' => self::column($monthActual, $monthBudget),
            'ytd' => self::column($ytdActual, $ytdBudget),
            'year_budget' => $yearBudget,
        ];
    }

    /** @return array{actual: int, budget: int, difference: int, percent: ?int} */
    private static function column(int $actual, int $budget): array
    {
        return ['actual' => $actual, 'budget' => $budget, 'difference' => $actual - $budget, 'percent' => self::percent($actual - $budget, $budget)];
    }

    /**
     * @param  list<array{month: array{actual: int, budget: int, difference: int, percent: ?int}, ytd: array{actual: int, budget: int, difference: int, percent: ?int}, year_budget: int}>  $rows
     * @return array{month: array{actual: int, budget: int, difference: int, percent: ?int}, ytd: array{actual: int, budget: int, difference: int, percent: ?int}, year_budget: int}
     */
    private static function total(array $rows): array
    {
        return self::figures(
            array_sum(array_map(fn (array $row): int => $row['month']['actual'], $rows)),
            array_sum(array_map(fn (array $row): int => $row['month']['budget'], $rows)),
            array_sum(array_map(fn (array $row): int => $row['ytd']['actual'], $rows)),
            array_sum(array_map(fn (array $row): int => $row['ytd']['budget'], $rows)),
            array_sum(array_map(fn (array $row): int => $row['year_budget'], $rows)),
        );
    }
}
