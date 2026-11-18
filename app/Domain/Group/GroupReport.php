<?php

declare(strict_types=1);

namespace App\Domain\Group;

use App\Actions\Group\ConsolidateGroup;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Models\Account;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRun;
use App\Models\GroupEntry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Koncern → Koncernrapporter (ADR-060, addendum 2026-11-07, 3.2 S2-1.6;
 * ADR-081 E26): the consolidated income statement and balance sheet of one
 * stored run, for the screen, the CSV file and the PDF. Everything comes from
 * the run and the group ledger entries the run applied, never from a new
 * reading of the books: the column Koncernen is the run's output, the column
 * Elimineringar is the rows of the applied entries per group account, and
 * Företagen is the difference, the companies' own figures summed after the
 * account map. Företagen plus Elimineringar is therefore Koncernen on every
 * row, and the test proves that Företagen equals a run without the entries.
 *
 * Who sees what (ADR-060 2.2, E24 and E25 rules, decided per run by
 * ConsolidateGroup::runLevel from the companies the run read):
 *  - NONE, and a run withheld from a CONSOLIDATED viewer (fewer than two
 *    companies, one left out or cut), answer 404 like a run that does not
 *    exist.
 *  - The breakdown into Företagen and Elimineringar per account is shown
 *    only with FULL access AND reports:view (not limited to dimensions) in
 *    every company the run read: an entry may concern a company that has
 *    left the group since (E25, P1-2), and its rows would show that
 *    company's intra-group figures.
 *  - Everyone else who sees the run gets the column Koncernen only, the
 *    elimination totals per type as ConsolidationView gives them to a
 *    CONSOLIDATED viewer, and the member names.
 *
 * The label (Consolidation::LABEL) is on the screen, in print, as the first
 * line of the CSV, at the top and in the footer of every PDF page, and the
 * file names start with "intern-koncernrapport" (ADR-060 2.4, S2-R14).
 *
 * TODO(legal-verify): ADR-060 H5 — is "Intern koncernrapport, inte en
 * koncernredovisning enligt ÅRL" enough to keep these reports from being
 * taken for koncernredovisning under ÅRL 7 kap. (and K3 1.6), also under
 * marketing law, once the files leave NONOX (CSV, PDF, file names)? The
 * rule sheet rates S2-R14 medium; the jurist reviews the wording first.
 *
 * TODO(legal-verify): ÅRL 7 kap. 1–3 §§ duty and small-group exemption; no
 * thresholds stated until verified.
 */
final class GroupReport
{
    /**
     * The report of a run, for whoever may see it.
     *
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException when the user may not see the group or the run
     */
    public static function build(GroupAccess $access, CorporateGroupRun $run): array
    {
        $group = $access->corporateGroup($run->corporate_group_id);
        // Decided by the companies the run read, not by the group's members today (cold review E26, P1).
        $level = ConsolidateGroup::runLevel($access, $group, $run);

        if (! $level->sees()) {
            throw (new ModelNotFoundException)->setModel(CorporateGroupRun::class, [$run->id]);
        }

        $detailed = $level === GroupAccessLevel::Full;

        // The figures the person may see of the run, with the elimination totals per type as E25 decided them.
        $view = ConsolidationView::present($run, $detailed ? GroupAccessLevel::Full : GroupAccessLevel::Consolidated);

        $eliminated = [];
        $entriesIntact = true;
        $names = [];

        if ($detailed) {
            [$eliminated, $entriesIntact] = self::eliminatedPerAccount($group, $run);
            $names = self::missingNames($group, $run, array_keys($eliminated));
        }

        $breakdown = $detailed && $entriesIntact;
        $income = self::income($run->output['income']['groups'] ?? [], $breakdown ? $eliminated : null, $names);
        $balance = self::balance($run->output['balance'] ?? [], $breakdown ? $eliminated : null, $names);

        return [
            'level' => $level,
            'detailed' => $breakdown,
            'entries_intact' => $entriesIntact,
            'label' => __(Consolidation::LABEL),
            'group' => $group,
            'run_id' => $run->id,
            'from' => $view['from'],
            'to' => $view['to'],
            'created_at' => $run->created_at,
            'runner' => $run->relationLoaded('runner') ? $run->runner?->name : null,
            'preliminary' => $run->preliminary,
            'inputs_hash' => $run->inputs_hash,
            'output_hash' => $run->output_hash,
            'intact' => $run->intact(),
            'members' => $view['members'],
            'excluded_count' => $view['excluded_count'],
            'cut' => $view['cut'],
            'eliminations' => $view['eliminations'],
            'eliminated_entries' => $view['eliminated_entries'],
            'skipped_entries' => $view['skipped_entries'],
            'income' => $income,
            'balance' => $balance,
            'result_outside_period' => $view['result_outside_period'],
            // The column Koncernen must be the run's own figures; a difference would be a fault in this report.
            'consistent' => $income['result']['group'] === (int) ($run->output['income']['result'] ?? 0)
                && $balance['assets']['total']['group'] === (int) ($run->output['balance']['assets']['total'] ?? 0)
                && $balance['equity']['total']['group'] === (int) ($run->output['balance']['equity']['total'] ?? 0),
        ];
    }

    /**
     * The applied entries' rows summed per group account, debit positive,
     * and whether every entry the run names is still there with the same
     * hash (the group ledger is append-only, so a difference means the
     * stored data was changed outside NONOX).
     *
     * @return array{0: array<int, int>, 1: bool}
     */
    private static function eliminatedPerAccount(CorporateGroup $group, CorporateGroupRun $run): array
    {
        $named = [];

        foreach ($run->inputs['group_entries'] ?? [] as $entry) {
            $named[(int) $entry['id']] = (string) $entry['hash'];
        }

        if ($named === []) {
            return [[], true];
        }

        // The whole entry with its rows: the hash is recomputed from what is stored, so a row changed outside NONOX shows.
        $entries = GroupEntry::query()
            ->where('corporate_group_id', $group->id)
            ->whereIn('id', array_keys($named))
            ->with('rows')
            ->get();

        $intact = $entries->count() === count($named)
            && $entries->every(fn (GroupEntry $entry): bool => hash_equals($named[$entry->id] ?? '', (string) $entry->hash)
                && hash_equals((string) $entry->hash, $entry->recomputedHash()));
        $amounts = [];
        $byType = [];

        foreach ($entries as $entry) {
            foreach ($entry->rows as $row) {
                $amounts[$row->group_account_number] = ($amounts[$row->group_account_number] ?? 0) + $row->debit_ore - $row->credit_ore;
            }

            $sign = $entry->kind === GroupEntryKind::Reversal ? -1 : 1;
            $byType[$entry->type->value] = ($byType[$entry->type->value] ?? 0) + $sign * (int) $entry->total_ore;
        }

        ksort($amounts);
        $byType = array_filter($byType, fn (int $amount): bool => $amount !== 0);
        ksort($byType);
        $stored = array_map(fn (mixed $amount): int => (int) $amount, $run->output['eliminations']['by_type'] ?? []);
        ksort($stored);
        // The amounts per type must be the ones the run stored.
        $intact = $intact && $byType === $stored;

        return [$amounts, $intact];
    }

    /**
     * Names of group accounts that only an elimination touches (eliminated
     * to zero, so not in the run's output), named as the engine names
     * accounts: the parent's chart, else the BAS standard name. Only read
     * with FULL access, which includes reports:view in the parent.
     *
     * @param  list<int>  $numbers
     * @return array<int, string>
     */
    private static function missingNames(CorporateGroup $group, CorporateGroupRun $run, array $numbers): array
    {
        $present = [];

        foreach (self::outputRows($run->output) as $row) {
            $present[(int) $row['number']] = true;
        }

        $missing = array_values(array_filter($numbers, fn (int $number): bool => ! isset($present[$number])));

        if ($missing === []) {
            return [];
        }

        $parent = CurrentCompany::run($group->parent_company_id, fn (): array => Account::query()
            ->whereIn('number', $missing)
            ->pluck('name', 'number')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());
        $names = [];

        foreach ($missing as $number) {
            $names[$number] = $parent[$number] ?? Consolidation::basNames()[$number] ?? '';
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<array<string, mixed>>
     */
    private static function outputRows(array $output): array
    {
        $rows = [];

        foreach ([$output['income']['groups'] ?? [], $output['balance']['assets']['groups'] ?? [], $output['balance']['equity']['groups'] ?? []] as $groups) {
            foreach ($groups as $group) {
                array_push($rows, ...$group['rows']);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $groups  the run's income statement groups
     * @param  ?array<int, int>  $eliminated  debit positive per group account; null without the breakdown
     * @param  array<int, string>  $names
     * @return array{groups: list<array<string, mixed>>, result: array{companies: ?int, eliminations: ?int, group: int}}
     */
    private static function income(array $groups, ?array $eliminated, array $names): array
    {
        $byKey = array_column($groups, null, 'key');
        $result = self::zero($eliminated !== null);
        $totals = [];
        $out = [];

        foreach (LedgerReports::INCOME_GROUPS as $definition) {
            // Income is presented positive: the ledger's credit.
            $rows = self::rows($byKey[$definition['key']]['rows'] ?? [], $eliminated, $names, $definition['from'], $definition['to'], -1);
            $total = self::sum($rows, $eliminated !== null);
            $totals[$definition['key']] = $total;
            $result = self::add($result, $total);
            $subtotal = LedgerReports::INCOME_SUBTOTALS[$definition['key']] ?? null;

            $out[] = [
                'key' => $definition['key'],
                'label' => __($definition['label']),
                'rows' => $rows,
                'total' => $total,
                'subtotal' => $subtotal === null ? null : [
                    'label' => __($subtotal['label']),
                    ...array_reduce(
                        array_intersect_key($totals, array_flip($subtotal['groups'])),
                        fn (array $carry, array $columns): array => self::add($carry, $columns),
                        self::zero($eliminated !== null),
                    ),
                ],
            ];
        }

        return ['groups' => $out, 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $balance  the run's balance sheet
     * @param  ?array<int, int>  $eliminated  debit positive per group account; null without the breakdown
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private static function balance(array $balance, ?array $eliminated, array $names): array
    {
        $breakdown = $eliminated !== null;
        $sides = [];

        foreach (['assets' => __('Tillgångar'), 'equity' => __('Eget kapital och skulder')] as $side => $label) {
            $byKey = array_column($balance[$side]['groups'] ?? [], null, 'key');
            $groups = [];
            $total = self::zero($breakdown);

            foreach (LedgerReports::BALANCE_GROUPS as $definition) {
                if ($definition['side'] !== $side) {
                    continue;
                }

                $rows = self::rows($byKey[$definition['key']]['rows'] ?? [], $eliminated, $names, $definition['from'], $definition['to'], $side === 'assets' ? 1 : -1);
                $groupTotal = self::sum($rows, $breakdown);
                $total = self::add($total, $groupTotal);
                $groups[] = ['key' => $definition['key'], 'label' => __($definition['label']), 'rows' => $rows, 'total' => $groupTotal];
            }

            $sides[$side] = ['label' => $label, 'groups' => $groups, 'total' => $total];
        }

        // The result not yet booked to equity closes the two sides, per column, as in the run.
        $unbooked = [
            'companies' => $breakdown ? $sides['assets']['total']['companies'] - $sides['equity']['total']['companies'] : null,
            'eliminations' => $breakdown ? $sides['assets']['total']['eliminations'] - $sides['equity']['total']['eliminations'] : null,
            'group' => $sides['assets']['total']['group'] - $sides['equity']['total']['group'],
        ];
        $sides['equity']['unbooked'] = $unbooked;
        $sides['equity']['total'] = self::add($sides['equity']['total'], $unbooked);

        return $sides;
    }

    /**
     * The rows of one statement group: the run's rows, plus every group
     * account in the range that only an elimination touches, by number.
     *
     * @param  list<array<string, mixed>>  $rows  the run's rows, amounts as presented
     * @param  ?array<int, int>  $eliminated  debit positive per group account
     * @param  array<int, string>  $names
     * @return list<array{number: int, name: string, companies: ?int, eliminations: ?int, group: int}>
     */
    private static function rows(array $rows, ?array $eliminated, array $names, int $from, int $to, int $sign): array
    {
        $byNumber = [];

        foreach ($rows as $row) {
            $byNumber[(int) $row['number']] = ['name' => (string) $row['name'], 'group' => (int) $row['amount']];
        }

        foreach (array_keys($eliminated ?? []) as $number) {
            if ($number >= $from && $number <= $to && ! isset($byNumber[$number])) {
                $byNumber[$number] = ['name' => $names[$number] ?? '', 'group' => 0];
            }
        }

        ksort($byNumber);
        $out = [];

        foreach ($byNumber as $number => $row) {
            $elimination = $eliminated === null ? null : $sign * ($eliminated[$number] ?? 0);

            $out[] = [
                'number' => $number,
                'name' => $row['name'],
                'companies' => $elimination === null ? null : $row['group'] - $elimination,
                'eliminations' => $elimination,
                'group' => $row['group'],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{companies: ?int, eliminations: ?int, group: int}>  $rows
     * @return array{companies: ?int, eliminations: ?int, group: int}
     */
    private static function sum(array $rows, bool $breakdown): array
    {
        return array_reduce($rows, fn (array $carry, array $row): array => self::add($carry, $row), self::zero($breakdown));
    }

    /** @return array{companies: ?int, eliminations: ?int, group: int} */
    private static function zero(bool $breakdown): array
    {
        return ['companies' => $breakdown ? 0 : null, 'eliminations' => $breakdown ? 0 : null, 'group' => 0];
    }

    /**
     * @param  array{companies: ?int, eliminations: ?int, group: int}  $carry
     * @param  array<string, mixed>  $columns
     * @return array{companies: ?int, eliminations: ?int, group: int}
     */
    private static function add(array $carry, array $columns): array
    {
        return [
            'companies' => $carry['companies'] === null ? null : $carry['companies'] + (int) $columns['companies'],
            'eliminations' => $carry['eliminations'] === null ? null : $carry['eliminations'] + (int) $columns['eliminations'],
            'group' => $carry['group'] + (int) $columns['group'],
        ];
    }
}
