<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Domain\Accounting\Reports\LedgerReports;
use App\Models\Account;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;

/**
 * "Rullande 12 månader" (ADR-081 gap 2 scope 5): twelve months from a given
 * month, across a fiscal year boundary. Each month is read from its fiscal
 * year's active forecast, else from that year's active budget; months up to
 * a forecast's cut-off are the outcome it was made from. In the income
 * statement's sign (revenue positive, costs negative). Four queries,
 * whatever the number of years, versions or lines. Reads only.
 */
final class RollingTwelveMonths
{
    public const int MONTHS = 12;

    /**
     * Whether a forecast's cut-off is more than a month behind the books: an
     * earlier month than the one before the last ended month (or the year's
     * last month, once the year is over).
     */
    public static function stale(BudgetVersion $version, FiscalYear $fiscalYear, ?CarbonImmutable $today = null): bool
    {
        if ($version->forecast_cutoff === null) {
            return false;
        }

        $behind = BudgetMonths::shift(($today ?? CarbonImmutable::now())->format('Y-m'), -2);
        $limit = min($behind, $fiscalYear->end_date->format('Y-m'));

        return $version->forecast_cutoff->format('Y-m') < $limit;
    }

    /**
     * @param  string  $from  'Y-m', the first of the twelve months
     * @return array{
     *     months: list<string>,
     *     sources: array<string, array{version_id: ?int, name: ?string, kind: ?BudgetKind, actual: bool, cutoff: ?string, stale: bool}>,
     *     groups: list<array{key: string, label: string, rows: list<array{number: int, name: string, amounts: array<string, int>, total: int}>, amounts: array<string, int>, total: int}>,
     *     result: array{amounts: array<string, int>, total: int}
     * }
     */
    public static function build(string $from): array
    {
        $months = array_map(fn (int $offset): string => BudgetMonths::shift($from, $offset), range(0, self::MONTHS - 1));
        $start = "{$months[0]}-01";
        $end = (string) CarbonImmutable::createFromFormat('!Y-m', $months[self::MONTHS - 1])?->endOfMonth()->toDateString();

        $years = FiscalYear::query()->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->orderBy('start_date')->get();

        $versions = $years->isEmpty() ? collect() : BudgetVersion::query()
            ->whereIn('fiscal_year_id', $years->modelKeys())
            ->where('is_active', true)
            ->get(['id', 'fiscal_year_id', 'name', 'kind', 'forecast_cutoff']);

        $chosen = [];

        foreach ($years as $year) {
            $chosen[$year->id] = $versions->first(fn (BudgetVersion $version): bool => $version->fiscal_year_id === $year->id && $version->kind === BudgetKind::Forecast)
                ?? $versions->first(fn (BudgetVersion $version): bool => $version->fiscal_year_id === $year->id && $version->kind === BudgetKind::Budget);
        }

        $sources = [];

        foreach ($months as $month) {
            $year = $years->first(fn (FiscalYear $year): bool => $month >= $year->start_date->format('Y-m') && $month <= $year->end_date->format('Y-m'));
            $version = $year === null ? null : $chosen[$year->id];

            $sources[$month] = [
                'version_id' => $version?->id,
                'name' => $version?->name,
                'kind' => $version?->kind,
                'actual' => $version !== null && $version->forecast_cutoff !== null && $month <= $version->forecast_cutoff->format('Y-m'),
                'cutoff' => $version?->forecast_cutoff?->format('Y-m'),
                'stale' => $year !== null && $version !== null && self::stale($version, $year),
            ];
        }

        $versionIds = array_values(array_unique(array_filter(array_column($sources, 'version_id'))));
        $amounts = [];

        if ($versionIds !== []) {
            $lines = BudgetScope::linesOf($versionIds)
                ->whereBetween('period', [$start, $end])
                ->groupBy('budget_version_id', 'account_id', 'period')
                ->select('budget_version_id', 'account_id')
                ->selectRaw("to_char(period, 'YYYY-MM') AS month, SUM(amount_ore) AS amount")
                ->toBase()
                ->get();

            foreach ($lines as $line) {
                $month = (string) $line->month;

                // A month is read from one version only: its own fiscal year's.
                if (($sources[$month]['version_id'] ?? null) !== (int) $line->budget_version_id) {
                    continue;
                }

                $amounts[(int) $line->account_id][$month] = ($amounts[(int) $line->account_id][$month] ?? 0) - (int) $line->amount;
            }
        }

        $accounts = $amounts === [] ? collect() : Account::query()->whereIn('id', array_keys($amounts))->orderBy('number')->get(['id', 'number', 'name']);
        $groups = [];
        $result = array_fill_keys($months, 0);

        foreach (LedgerReports::INCOME_GROUPS as $group) {
            $rows = [];
            $sum = array_fill_keys($months, 0);

            foreach ($accounts->filter(fn (Account $account): bool => $account->number >= $group['from'] && $account->number <= $group['to']) as $account) {
                $perMonth = [];

                foreach ($months as $month) {
                    $perMonth[$month] = $amounts[$account->id][$month] ?? 0;
                    $sum[$month] += $perMonth[$month];
                    $result[$month] += $perMonth[$month];
                }

                if (array_filter($perMonth) === []) {
                    continue;
                }

                $rows[] = ['number' => $account->number, 'name' => $account->name, 'amounts' => $perMonth, 'total' => array_sum($perMonth)];
            }

            if ($rows !== []) {
                $groups[] = ['key' => $group['key'], 'label' => __($group['label']), 'rows' => $rows, 'amounts' => $sum, 'total' => array_sum($sum)];
            }
        }

        return ['months' => $months, 'sources' => $sources, 'groups' => $groups, 'result' => ['amounts' => $result, 'total' => array_sum($result)]];
    }
}
