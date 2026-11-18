<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Budget\BudgetComparison;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetScope;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\VoucherRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Project P&L (ADR-081 gap 6, E7): revenue, costs by group, contribution and
 * margin for a project's whole life or one fiscal year, against the active
 * budget's lines on the project (gap 2) and against the project's estimate
 * (contract sum and estimated total cost). Read only; nothing here writes to
 * the books.
 *
 * The posted outcome is read only through LedgerQuery, one fiscal year at a
 * time, and the years are joined with UNION ALL into one statement: each
 * figure costs one query whatever the number of years, accounts or rows.
 * For a member limited to cost centres or projects the payroll vouchers and
 * their corrections are left out (ADR-081 gap 10, LedgerQuery::$withoutPayroll).
 *
 * Amounts are in the income statement's sign, as the Resultatrapport and
 * Budget mot utfall: revenue positive, costs negative, so a positive
 * difference is always better than the budget. For a limited member the
 * personnel accounts are left out entirely (PERSONNEL_FROM).
 */
final class ProjectResults
{
    /**
     * The personnel accounts, left out entirely for a member limited to cost
     * centres or projects: not only payroll vouchers, but also salaries
     * imported by SIE or booked by hand, and their budget (owner decision 17).
     * On a project with one person, they are that person's pay.
     * TODO(legal-verify): Resultatrapport and Huvudbok for a limited member
     * (E1) leave out only vouchers with source PAYROLL, so they still show
     * personnel accounts from SIE imports and manual vouchers on the
     * member's units; whether those must be hidden there too.
     */
    public const int PERSONNEL_FROM = 7000;

    public const int PERSONNEL_TO = 7699;

    /**
     * The groups of a project, by BAS account class. A presentation choice,
     * not a legal rule. The contribution (täckningsbidrag) is revenue
     * (3000–3999) minus material and goods (40–43, 47–49), subcontracting,
     * purchases with reverse charge or from abroad and other external costs
     * (44–46, 50–69) and personnel (70–76). Depreciation, other operating
     * items and the financial items (7700–8998, financial income included)
     * come below it and give the project result, as the income statement
     * keeps financial income out of revenue.
     *
     * @var list<array{key: string, label: string, ranges: list<array{0: int, 1: int}>, contribution: bool}>
     */
    public const array GROUPS = [
        ['key' => 'revenue', 'label' => 'Intäkter', 'ranges' => [[3000, 3999]], 'contribution' => true],
        ['key' => 'material', 'label' => 'Material och varor', 'ranges' => [[4000, 4399], [4700, 4999]], 'contribution' => true],
        ['key' => 'external', 'label' => 'Underentreprenader och externa kostnader', 'ranges' => [[4400, 4699], [5000, 6999]], 'contribution' => true],
        ['key' => 'personnel', 'label' => 'Personal', 'ranges' => [[self::PERSONNEL_FROM, self::PERSONNEL_TO]], 'contribution' => true],
        ['key' => 'below', 'label' => 'Avskrivningar och finansiella poster', 'ranges' => [[7700, BudgetMonths::LAST_ACCOUNT]], 'contribution' => false],
    ];

    /** The most rows a drill-down shows; the rest are in the account's Huvudbok. */
    public const int DRILL_LIMIT = 200;

    /**
     * One project's result. With one fiscal year the figures are also given
     * per month of that year.
     *
     * @param  Collection<int, FiscalYear>  $years  the years to read: all of them for the project's life, or one
     * @return array{
     *     groups: list<array{key: string, label: string, rows: list<array{number: int, name: string, actual: int, budget: int, difference: int, percent: ?int}>, total: array{actual: int, budget: int, difference: int, percent: ?int}, in_contribution: bool, subtotal: ?array{actual: int, budget: int, difference: int, percent: ?int}}>,
     *     months: list<string>,
     *     monthly: array<string, array<string, int>>,
     *     actual: array{revenue: int, costs: int, contribution: int, margin: ?int, result: int},
     *     budget: array{revenue: int, costs: int, contribution: int, margin: ?int, result: int},
     *     contribution: array{actual: int, budget: int, difference: int, percent: ?int},
     *     result: array{actual: int, budget: int, difference: int, percent: ?int},
     *     has_budget: bool,
     *     personnel_hidden: bool
     * }
     */
    public static function forProject(Project $project, Collection $years, bool $withoutPayroll, bool $withBudget): array
    {
        $perMonth = $years->count() === 1;
        $accounts = [];

        $actuals = self::union($years, $project->id, $withoutPayroll, fn (Builder $rows): Builder => $rows
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->whereBetween('accounts.number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])
            ->when($withoutPayroll, fn (Builder $query) => $query->whereNotBetween('accounts.number', [self::PERSONNEL_FROM, self::PERSONNEL_TO]))
            ->groupBy('accounts.number', 'accounts.name')
            ->groupByRaw("to_char(vouchers.voucher_date, 'YYYY-MM')")
            ->select('accounts.number', 'accounts.name')
            ->selectRaw("to_char(vouchers.voucher_date, 'YYYY-MM') AS month, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount"));

        foreach ($actuals?->get() ?? [] as $row) {
            $number = (int) $row->number;
            $accounts[$number] ??= ['name' => (string) $row->name, 'actual' => [], 'budget' => []];
            $accounts[$number]['actual'][(string) $row->month] = ($accounts[$number]['actual'][(string) $row->month] ?? 0) - (int) $row->amount;
        }

        $hasBudget = false;

        if ($withBudget && $years->isNotEmpty()) {
            $budget = BudgetScope::activeBudgetLines(self::ids($years))
                ->where('budget_lines.project_id', $project->id)
                ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
                ->when($withoutPayroll, fn (Builder $query) => $query->whereNotBetween('accounts.number', [self::PERSONNEL_FROM, self::PERSONNEL_TO]))
                ->groupBy('accounts.number', 'accounts.name')
                ->groupByRaw("to_char(budget_lines.period, 'YYYY-MM')")
                ->select('accounts.number', 'accounts.name')
                ->selectRaw("to_char(budget_lines.period, 'YYYY-MM') AS month, SUM(budget_lines.amount_ore) AS amount")
                ->toBase()
                ->get();

            foreach ($budget as $row) {
                $hasBudget = true;
                $number = (int) $row->number;
                $accounts[$number] ??= ['name' => (string) $row->name, 'actual' => [], 'budget' => []];
                $accounts[$number]['budget'][(string) $row->month] = ($accounts[$number]['budget'][(string) $row->month] ?? 0) - (int) $row->amount;
            }
        }

        ksort($accounts);

        /** @var FiscalYear|null $year */
        $year = $perMonth ? $years->first() : null;
        $months = $year instanceof FiscalYear ? BudgetMonths::of($year) : [];
        $groups = [];
        $monthly = ['contribution' => array_fill_keys($months, 0), 'result' => array_fill_keys($months, 0)];

        foreach (self::GROUPS as $group) {
            $rows = [];
            $groupMonths = array_fill_keys($months, 0);

            foreach ($accounts as $number => $account) {
                if (self::groupOf($number) !== $group['key']) {
                    continue;
                }

                $rows[] = ['number' => $number, 'name' => $account['name'], ...self::column(array_sum($account['actual']), array_sum($account['budget']))];

                foreach ($months as $month) {
                    $amount = $account['actual'][$month] ?? 0;
                    $groupMonths[$month] += $amount;
                    $monthly['result'][$month] += $amount;

                    if ($group['contribution']) {
                        $monthly['contribution'][$month] += $amount;
                    }
                }
            }

            $monthly[$group['key']] = $groupMonths;
            $groups[] = [
                'key' => $group['key'],
                'label' => __($group['label']),
                'rows' => $rows,
                'total' => self::column(array_sum(array_column($rows, 'actual')), array_sum(array_column($rows, 'budget'))),
                'in_contribution' => $group['contribution'],
            ];
        }

        $revenue = $groups[0]['total'];
        $above = array_filter($groups, fn (array $group): bool => $group['in_contribution']);
        $contribution = self::column(
            array_sum(array_map(fn (array $group): int => $group['total']['actual'], $above)),
            array_sum(array_map(fn (array $group): int => $group['total']['budget'], $above)),
        );
        $result = self::column(
            array_sum(array_map(fn (array $group): int => $group['total']['actual'], $groups)),
            array_sum(array_map(fn (array $group): int => $group['total']['budget'], $groups)),
        );

        $visible = array_values(array_filter($groups, fn (array $group): bool => $group['rows'] !== []));
        $last = null;

        foreach ($visible as $index => $group) {
            if ($group['in_contribution']) {
                $last = $index;
            }
        }

        // The contribution is shown after the last group it covers.
        $shown = [];

        foreach ($visible as $index => $group) {
            $shown[] = [...$group, 'subtotal' => $index === $last ? $contribution : null];
        }

        return [
            'groups' => $shown,
            'months' => $months,
            'monthly' => $monthly,
            'actual' => self::summary($revenue['actual'], $contribution['actual'], $result['actual']),
            'budget' => self::summary($revenue['budget'], $contribution['budget'], $result['budget']),
            'contribution' => $contribution,
            'result' => $result,
            'has_budget' => $hasBudget,
            'personnel_hidden' => $withoutPayroll,
        ];
    }

    /**
     * The project's outcome against its estimate (gap 6 scope 3): how much of
     * the contract sum is booked as revenue and how much of the estimated
     * total cost is used. Only meaningful for the project's whole life.
     *
     * @param  int  $revenue  booked revenue, positive
     * @param  int  $costs  booked costs, positive
     * @return array{contract: ?int, revenue_share: ?int, revenue_left: ?int, estimated_cost: ?int, cost_share: ?int, cost_left: ?int, expected_contribution: ?int, expected_margin: ?int}
     */
    public static function estimate(Project $project, int $revenue, int $costs): array
    {
        $contract = $project->contract_sum_ore?->ore;
        $estimated = $project->estimated_cost_ore?->ore;
        $expected = $contract !== null && $estimated !== null ? $contract - $estimated : null;

        return [
            'contract' => $contract,
            'revenue_share' => $contract === null ? null : self::share($revenue, $contract),
            'revenue_left' => $contract === null ? null : $contract - $revenue,
            'estimated_cost' => $estimated,
            'cost_share' => $estimated === null ? null : self::share($costs, $estimated),
            'cost_left' => $estimated === null ? null : $estimated - $costs,
            'expected_contribution' => $expected,
            'expected_margin' => $expected === null ? null : self::share($expected, (int) $contract),
        ];
    }

    /**
     * The posted rows of one result account on the project, for the
     * drill-down: oldest first, at most DRILL_LIMIT. One query.
     *
     * @param  Collection<int, FiscalYear>  $years
     * @return array{rows: list<array{row_id: int, voucher_id: string, date: string, reference: string, row_text: string, voucher_text: string, amount: int}>, more: bool}
     */
    public static function rows(Project $project, Collection $years, int $accountNumber, bool $withoutPayroll): array
    {
        if ($withoutPayroll && $accountNumber >= self::PERSONNEL_FROM && $accountNumber <= self::PERSONNEL_TO) {
            return ['rows' => [], 'more' => false];
        }

        $union = self::union($years, $project->id, $withoutPayroll, fn (Builder $rows): Builder => $rows
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->where('accounts.number', $accountNumber)
            ->select([
                'voucher_rows.id AS row_id', 'vouchers.id AS voucher_id', 'vouchers.voucher_date', 'voucher_series.code AS series_code',
                'vouchers.number', 'vouchers.description AS voucher_text', 'voucher_rows.description AS row_text',
                'voucher_rows.debit_ore', 'voucher_rows.credit_ore',
            ]));

        if ($union === null) {
            return ['rows' => [], 'more' => false];
        }

        $found = DB::query()->fromSub($union, 'project_rows')
            ->orderBy('voucher_date')->orderBy('series_code')->orderBy('number')->orderBy('row_id')
            ->limit(self::DRILL_LIMIT + 1)
            ->get();

        $rows = [];

        foreach ($found->take(self::DRILL_LIMIT) as $row) {
            $rows[] = [
                'row_id' => (int) $row->row_id,
                'voucher_id' => (string) $row->voucher_id,
                'date' => substr((string) $row->voucher_date, 0, 10),
                'reference' => "{$row->series_code} {$row->number}",
                'row_text' => (string) $row->row_text,
                'voucher_text' => (string) $row->voucher_text,
                // Result sign: revenue positive, costs negative.
                'amount' => (int) $row->credit_ore - (int) $row->debit_ore,
            ];
        }

        return ['rows' => $rows, 'more' => $found->count() > self::DRILL_LIMIT];
    }

    /**
     * Revenue, costs and contribution per project over the given years, for
     * the project list: two queries (outcome and, with $withBudget, the
     * active budgets), whatever the number of projects.
     *
     * @param  Collection<int, FiscalYear>  $years
     * @param  list<int>|null  $projectIds  only these projects (a limited member's), or all
     * @return array<int, array{actual: array{revenue: int, costs: int, contribution: int, margin: ?int, result: int}, budget: ?array{revenue: int, costs: int, contribution: int, margin: ?int, result: int}}>
     */
    public static function perProject(Collection $years, ?array $projectIds, bool $withoutPayroll, bool $withBudget): array
    {
        $results = [];

        $actuals = self::union($years, null, $withoutPayroll, fn (Builder $rows): Builder => $rows
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->whereBetween('accounts.number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])
            ->whereNotNull('voucher_rows.project_id')
            ->when($withoutPayroll, fn (Builder $query) => $query->whereNotBetween('accounts.number', [self::PERSONNEL_FROM, self::PERSONNEL_TO]))
            ->when($projectIds !== null, fn (Builder $query) => $query->whereIn('voucher_rows.project_id', $projectIds))
            ->groupBy('voucher_rows.project_id')
            ->select('voucher_rows.project_id')
            ->selectRaw('SUM(CASE WHEN accounts.number <= 3999 THEN voucher_rows.credit_ore - voucher_rows.debit_ore ELSE 0 END) AS revenue, SUM(CASE WHEN accounts.number <= 7699 THEN voucher_rows.credit_ore - voucher_rows.debit_ore ELSE 0 END) AS contribution, SUM(voucher_rows.credit_ore - voucher_rows.debit_ore) AS result'));

        $actual = [];

        foreach ($actuals?->get() ?? [] as $row) {
            $id = (int) $row->project_id;
            $actual[$id]['revenue'] = ($actual[$id]['revenue'] ?? 0) + (int) $row->revenue;
            $actual[$id]['contribution'] = ($actual[$id]['contribution'] ?? 0) + (int) $row->contribution;
            $actual[$id]['result'] = ($actual[$id]['result'] ?? 0) + (int) $row->result;
        }

        $budget = [];

        if ($withBudget && $years->isNotEmpty()) {
            $lines = BudgetScope::activeBudgetLines(self::ids($years))
                ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
                ->whereNotNull('budget_lines.project_id')
                ->when($withoutPayroll, fn (Builder $query) => $query->whereNotBetween('accounts.number', [self::PERSONNEL_FROM, self::PERSONNEL_TO]))
                ->when($projectIds !== null, fn (Builder $query) => $query->whereIn('budget_lines.project_id', $projectIds))
                ->groupBy('budget_lines.project_id')
                ->select('budget_lines.project_id')
                ->selectRaw('SUM(CASE WHEN accounts.number <= 3999 THEN -budget_lines.amount_ore ELSE 0 END) AS revenue, SUM(CASE WHEN accounts.number <= 7699 THEN -budget_lines.amount_ore ELSE 0 END) AS contribution, SUM(-budget_lines.amount_ore) AS result')
                ->toBase()
                ->get();

            foreach ($lines as $row) {
                $budget[(int) $row->project_id] = ['revenue' => (int) $row->revenue, 'contribution' => (int) $row->contribution, 'result' => (int) $row->result];
            }
        }

        foreach (array_unique([...array_keys($actual), ...array_keys($budget)]) as $id) {
            $results[$id] = [
                'actual' => self::summary($actual[$id]['revenue'] ?? 0, $actual[$id]['contribution'] ?? 0, $actual[$id]['result'] ?? 0),
                'budget' => isset($budget[$id]) ? self::summary($budget[$id]['revenue'], $budget[$id]['contribution'], $budget[$id]['result']) : null,
            ];
        }

        return $results;
    }

    /** The group key of a result account. */
    public static function groupOf(int $number): ?string
    {
        foreach (self::GROUPS as $group) {
            foreach ($group['ranges'] as [$from, $to]) {
                if ($number >= $from && $number <= $to) {
                    return $group['key'];
                }
            }
        }

        return null;
    }

    /**
     * A part of a whole in basis points (1234 = 12,34 %), half up; null when
     * the whole is zero.
     */
    public static function share(int $part, int $whole): ?int
    {
        if ($whole === 0) {
            return null;
        }

        return BigDecimal::of($part)->multipliedBy(10000)->dividedBy(abs($whole), 0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * The posted rows of every given year, each read through LedgerQuery for
     * that year, joined with UNION ALL: one statement. Null without years.
     *
     * @param  Collection<int, FiscalYear>  $years
     * @param  Closure(Builder<VoucherRow>): Builder<VoucherRow>  $shape
     */
    private static function union(Collection $years, ?int $projectId, bool $withoutPayroll, Closure $shape): ?QueryBuilder
    {
        $union = null;

        foreach ($years as $year) {
            $ledger = new LedgerQuery($year, $year->start_date, $year->end_date, projectId: $projectId, withoutPayroll: $withoutPayroll);
            $query = $shape($ledger->rows())->toBase();
            $union = $union === null ? $query : $union->unionAll($query);
        }

        return $union;
    }

    /**
     * @param  Collection<int, FiscalYear>  $years
     * @return list<int>
     */
    private static function ids(Collection $years): array
    {
        return array_values(array_map(fn (FiscalYear $year): int => $year->id, $years->all()));
    }

    /**
     * Costs are those above the contribution (4000–7699); the result also
     * takes in the items below it.
     *
     * @return array{revenue: int, costs: int, contribution: int, margin: ?int, result: int}
     */
    private static function summary(int $revenue, int $contribution, int $result): array
    {
        return [
            'revenue' => $revenue,
            'costs' => $revenue - $contribution,
            'contribution' => $contribution,
            'margin' => $revenue > 0 ? self::share($contribution, $revenue) : null,
            'result' => $result,
        ];
    }

    /** @return array{actual: int, budget: int, difference: int, percent: ?int} */
    private static function column(int $actual, int $budget): array
    {
        return ['actual' => $actual, 'budget' => $budget, 'difference' => $actual - $budget, 'percent' => BudgetComparison::percent($actual - $budget, $budget)];
    }
}
