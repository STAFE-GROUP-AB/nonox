<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Domain\Accounting\Reports\LedgerReports;
use App\Models\Company;
use App\Models\CorporateGroupRun;

/**
 * What a stored consolidation run shows (ADR-060, addendum 2026-11-07, 2.2
 * and 2.4). The statements come from the run's output, which holds
 * consolidated figures only. Per-company facts (which year was read, the
 * days a subsidiary was a member, why a company was left out) come from the
 * inputs and are shown with FULL access only. A CONSOLIDATED viewer gets
 * member names and counts, never a per-company figure, date or account.
 */
final class ConsolidationView
{
    /**
     * The keys: level, label, from, to, preliminary, income, result, balance,
     * unbooked_result, result_outside_period, eliminations (totals per type),
     * eliminated_entries, skipped_entries (FULL only), members, cut,
     * excluded_count, companies (FULL only) and excluded (FULL only).
     *
     * @return array<string, mixed>
     */
    public static function present(CorporateGroupRun $run, GroupAccessLevel $level): array
    {
        $output = $run->output;
        $inputs = $run->inputs;
        $full = $level === GroupAccessLevel::Full;

        $ids = [
            ...array_map(fn (array $company): int => (int) $company['company_id'], $inputs['companies'] ?? []),
            ...array_map(fn (array $company): int => (int) $company['company_id'], $inputs['excluded'] ?? []),
        ];
        $names = $ids === [] ? [] : Company::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        $name = fn (int $id): string => (string) ($names[$id] ?? __('Företag som inte längre finns'));

        return [
            'level' => $level,
            'label' => __(Consolidation::LABEL),
            'from' => $run->period_from->toDateString(),
            'to' => $run->period_to->toDateString(),
            'preliminary' => $run->preliminary,
            'income' => self::income($output['income']['groups'] ?? []),
            'result' => (int) ($output['income']['result'] ?? 0),
            'balance' => self::balance($output['balance'] ?? []),
            'unbooked_result' => (int) ($output['balance']['unbooked_result'] ?? 0),
            'result_outside_period' => (int) ($output['result_outside_period'] ?? 0),
            // Elimination totals per type, net of reversals (ADR-060 2.2 lets a consolidated viewer see them; ADR-081 E25).
            'eliminations' => self::eliminations($output['eliminations'] ?? [], $full),
            'eliminated_entries' => (int) ($output['eliminations']['entries'] ?? 0),
            'skipped_entries' => $full ? count($inputs['group_entries_skipped'] ?? []) : 0,
            // Member names may be shown to a consolidated viewer (2.2); which company is the parent is not a figure.
            'members' => array_map(fn (array $company): string => $name((int) $company['company_id']), $inputs['companies'] ?? []),
            'cut' => (int) ($output['companies']['cut'] ?? 0),
            'excluded_count' => count($inputs['excluded'] ?? []),
            'companies' => $full ? array_map(fn (array $company): array => [
                'name' => $name((int) $company['company_id']),
                'role' => (string) $company['role'],
                'income_from' => $company['income_from'],
                'income_to' => $company['income_to'],
                'balance' => (bool) $company['balance'],
                'fiscal_year_closed' => (bool) $company['fiscal_year_closed'],
                'preliminary' => (bool) $company['preliminary'],
                'posted_vouchers' => (int) $company['posted_vouchers'],
            ], $inputs['companies'] ?? []) : [],
            'excluded' => $full ? array_map(fn (array $company): array => [
                'name' => $name((int) $company['company_id']),
                'reason' => match ($company['reason']) {
                    Consolidation::EXCLUDED_NO_FISCAL_YEAR => __('har inget räkenskapsår som omfattar hela perioden'),
                    default => __('ingick inte i koncernen under perioden'),
                },
            ], $inputs['excluded'] ?? []) : [],
        ];
    }

    /**
     * Per type: the amount eliminated net of reversals, and the number of
     * entries. Someone who sees only the group's sums gets the amount only
     * for a type with standing entries between at least two pairs of
     * companies; with one pair the amount is that pair's own figure, so
     * only the number of entries is shown (cold review E25, P2-2).
     *
     * @param  array<string, mixed>  $eliminations  the run output's eliminations
     * @return list<array{label: string, amount: ?int, entries: int}>
     */
    private static function eliminations(array $eliminations, bool $full): array
    {
        $amounts = $eliminations['by_type'] ?? [];
        $pairs = $eliminations['pairs_by_type'] ?? [];
        $rows = [];

        foreach ($eliminations['entries_by_type'] ?? [] as $type => $entries) {
            $amount = (int) ($amounts[$type] ?? 0);
            // OTHER is free manual adjustments with no pair behind them: never an amount without FULL access (re-check P3).
            $shown = $full || ($type !== EliminationType::Other->value && (int) ($pairs[$type] ?? 0) >= self::MINIMUM_PAIRS);

            if ($shown && $amount === 0) {
                continue;
            }

            $rows[] = ['label' => EliminationType::tryFrom((string) $type)?->label() ?? (string) $type, 'amount' => $shown ? $amount : null, 'entries' => (int) $entries];
        }

        return $rows;
    }

    /** A consolidated viewer sees a type's eliminated amount only with entries between at least this many pairs of companies. */
    public const int MINIMUM_PAIRS = 2;

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private static function income(array $groups): array
    {
        $labels = array_column(LedgerReports::INCOME_GROUPS, 'label', 'key');

        return array_map(fn (array $group): array => [
            'key' => (string) $group['key'],
            'label' => __($labels[$group['key']] ?? (string) $group['key']),
            'rows' => self::rows($group['rows']),
            'total' => (int) $group['total'],
            'subtotal' => $group['subtotal'] === null ? null : [
                'label' => __(LedgerReports::INCOME_SUBTOTALS[$group['key']]['label'] ?? ''),
                'amount' => (int) $group['subtotal'],
            ],
        ], $groups);
    }

    /**
     * @param  array<string, mixed>  $balance
     * @return array<string, array<string, mixed>>
     */
    private static function balance(array $balance): array
    {
        $labels = array_column(LedgerReports::BALANCE_GROUPS, 'label', 'key');
        $sides = [];

        foreach (['assets' => __('Tillgångar'), 'equity' => __('Eget kapital och skulder')] as $key => $label) {
            $sides[$key] = [
                'label' => $label,
                'groups' => array_map(fn (array $group): array => [
                    'key' => (string) $group['key'],
                    'label' => __($labels[$group['key']] ?? (string) $group['key']),
                    'rows' => self::rows($group['rows']),
                    'total' => (int) $group['total'],
                ], $balance[$key]['groups'] ?? []),
                'total' => (int) ($balance[$key]['total'] ?? 0),
            ];
        }

        return $sides;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{number: int, name: string, amount: int}>
     */
    private static function rows(array $rows): array
    {
        return array_map(fn (array $row): array => ['number' => (int) $row['number'], 'name' => (string) $row['name'], 'amount' => (int) $row['amount']], $rows);
    }
}
