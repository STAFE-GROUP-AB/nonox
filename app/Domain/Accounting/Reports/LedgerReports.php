<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reports;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ledger reports of AGENTS.md §7 (Saldobalans, Resultatrapport,
 * Balansrapport, Huvudbok/Kontoanalys, Verifikationslista), built from a
 * LedgerQuery. Amounts are signed öre. Each report runs a fixed number of
 * queries, whatever the size of the ledger.
 *
 * Grouping follows the BAS account classes. The income statement shows
 * revenue as positive and costs as negative; the balance sheet shows assets,
 * and equity and liabilities, as positive.
 */
final class LedgerReports
{
    /** @var list<array{key: string, label: string, from: int, to: int}> */
    public const array INCOME_GROUPS = [
        ['key' => 'revenue', 'label' => 'Rörelsens intäkter', 'from' => 3000, 'to' => 3999],
        ['key' => 'goods', 'label' => 'Varor, material och tjänster', 'from' => 4000, 'to' => 4999],
        ['key' => 'external', 'label' => 'Övriga externa kostnader', 'from' => 5000, 'to' => 6999],
        ['key' => 'personnel', 'label' => 'Personalkostnader', 'from' => 7000, 'to' => 7699],
        ['key' => 'depreciation', 'label' => 'Avskrivningar', 'from' => 7700, 'to' => 7899],
        ['key' => 'other_operating', 'label' => 'Övriga rörelsekostnader', 'from' => 7900, 'to' => 7999],
        ['key' => 'financial', 'label' => 'Finansiella poster', 'from' => 8000, 'to' => 8799],
        ['key' => 'appropriations', 'label' => 'Bokslutsdispositioner', 'from' => 8800, 'to' => 8899],
        ['key' => 'tax', 'label' => 'Skatt och årets resultat', 'from' => 8900, 'to' => 8999],
    ];

    /** Subtotals after a group: the label and the group keys it sums. */
    public const array INCOME_SUBTOTALS = [
        'other_operating' => ['label' => 'Rörelseresultat', 'groups' => ['revenue', 'goods', 'external', 'personnel', 'depreciation', 'other_operating']],
        'financial' => ['label' => 'Resultat efter finansiella poster', 'groups' => ['revenue', 'goods', 'external', 'personnel', 'depreciation', 'other_operating', 'financial']],
        'appropriations' => ['label' => 'Resultat före skatt', 'groups' => ['revenue', 'goods', 'external', 'personnel', 'depreciation', 'other_operating', 'financial', 'appropriations']],
    ];

    /** @var list<array{key: string, label: string, side: string, from: int, to: int}> */
    public const array BALANCE_GROUPS = [
        ['key' => 'fixed_assets', 'label' => 'Anläggningstillgångar', 'side' => 'assets', 'from' => 1000, 'to' => 1399],
        ['key' => 'current_assets', 'label' => 'Omsättningstillgångar', 'side' => 'assets', 'from' => 1400, 'to' => 1999],
        ['key' => 'equity', 'label' => 'Eget kapital', 'side' => 'equity', 'from' => 2000, 'to' => 2099],
        ['key' => 'untaxed_reserves', 'label' => 'Obeskattade reserver', 'side' => 'equity', 'from' => 2100, 'to' => 2199],
        ['key' => 'provisions', 'label' => 'Avsättningar', 'side' => 'equity', 'from' => 2200, 'to' => 2299],
        ['key' => 'long_term', 'label' => 'Långfristiga skulder', 'side' => 'equity', 'from' => 2300, 'to' => 2399],
        ['key' => 'current_liabilities', 'label' => 'Kortfristiga skulder', 'side' => 'equity', 'from' => 2400, 'to' => 2999],
    ];

    /** @var Collection<int, Account>|null */
    private ?Collection $accounts = null;

    /**
     * @return array{rows: list<array{number: int, name: string, opening: int, debit: int, credit: int, closing: int}>, totals: array{opening: int, debit: int, credit: int, closing: int}}
     */
    public function trialBalance(LedgerQuery $query): array
    {
        $opening = $query->openingBalances();
        $movements = $query->movements();
        $rows = [];

        foreach ($this->accounts() as $account) {
            $in = (int) ($opening[$account->id] ?? 0);
            $debit = $movements[$account->id]['debit'] ?? 0;
            $credit = $movements[$account->id]['credit'] ?? 0;

            if ($in === 0 && $debit === 0 && $credit === 0) {
                continue;
            }

            $rows[] = ['number' => $account->number, 'name' => $account->name, 'opening' => $in, 'debit' => $debit, 'credit' => $credit, 'closing' => $in + $debit - $credit];
        }

        return ['rows' => $rows, 'totals' => [
            'opening' => array_sum(array_column($rows, 'opening')),
            'debit' => array_sum(array_column($rows, 'debit')),
            'credit' => array_sum(array_column($rows, 'credit')),
            'closing' => array_sum(array_column($rows, 'closing')),
        ]];
    }

    /**
     * @return array{groups: list<array{key: string, label: string, rows: list<array{number: int, name: string, amount: int, compare: ?int}>, total: int, compare: ?int, subtotal: ?array{label: string, amount: int, compare: ?int}}>, result: int, compare_result: ?int, compare_label: ?string}
     */
    public function incomeStatement(LedgerQuery $query, ?LedgerQuery $compare = null): array
    {
        $amounts = $this->periodChange($query);
        $compared = $compare === null ? null : $this->periodChange($compare);
        $groups = [];
        $totals = [];
        $compareTotals = [];

        foreach (self::INCOME_GROUPS as $group) {
            $rows = [];

            foreach ($this->accountsBetween($group['from'], $group['to']) as $account) {
                $amount = -($amounts[$account->id] ?? 0);
                $before = $compared === null ? null : -($compared[$account->id] ?? 0);

                if ($amount !== 0 || ($before ?? 0) !== 0) {
                    $rows[] = ['number' => $account->number, 'name' => $account->name, 'amount' => $amount, 'compare' => $before];
                }
            }

            $totals[$group['key']] = array_sum(array_column($rows, 'amount'));
            $compareTotals[$group['key']] = $compared === null ? null : array_sum(array_map(fn (array $row): int => (int) $row['compare'], $rows));

            $subtotal = self::INCOME_SUBTOTALS[$group['key']] ?? null;

            $groups[] = [
                'key' => $group['key'],
                'label' => __($group['label']),
                'rows' => $rows,
                'total' => $totals[$group['key']],
                'compare' => $compareTotals[$group['key']],
                'subtotal' => $subtotal === null ? null : [
                    'label' => __($subtotal['label']),
                    'amount' => array_sum(array_intersect_key($totals, array_flip($subtotal['groups']))),
                    'compare' => $compared === null ? null : array_sum(array_map(intval(...), array_intersect_key($compareTotals, array_flip($subtotal['groups'])))),
                ],
            ];
        }

        return [
            'groups' => $groups,
            'result' => array_sum($totals),
            'compare_result' => $compared === null ? null : array_sum(array_map(intval(...), $compareTotals)),
            'compare_label' => $compare === null ? null : "{$compare->from->toDateString()} – {$compare->to->toDateString()}",
        ];
    }

    /**
     * @return array{sides: array<string, array{label: string, groups: list<array{label: string, rows: list<array{number: int, name: string, opening: int, change: int, closing: int}>, opening: int, change: int, closing: int}>, opening: int, change: int, closing: int}>, unbooked_result: array{opening: int, change: int, closing: int}}
     */
    public function balanceSheet(LedgerQuery $query): array
    {
        $opening = $query->openingBalances();
        $changes = $this->periodChange($query);
        $sides = [
            'assets' => ['label' => __('Tillgångar'), 'groups' => [], 'opening' => 0, 'change' => 0, 'closing' => 0],
            'equity' => ['label' => __('Eget kapital och skulder'), 'groups' => [], 'opening' => 0, 'change' => 0, 'closing' => 0],
        ];

        foreach (self::BALANCE_GROUPS as $group) {
            $sign = $group['side'] === 'assets' ? 1 : -1;
            $rows = [];

            foreach ($this->accountsBetween($group['from'], $group['to']) as $account) {
                $in = $sign * (int) ($opening[$account->id] ?? 0);
                $change = $sign * ($changes[$account->id] ?? 0);

                if ($in !== 0 || $change !== 0) {
                    $rows[] = ['number' => $account->number, 'name' => $account->name, 'opening' => $in, 'change' => $change, 'closing' => $in + $change];
                }
            }

            $sums = [
                'opening' => array_sum(array_column($rows, 'opening')),
                'change' => array_sum(array_column($rows, 'change')),
                'closing' => array_sum(array_column($rows, 'closing')),
            ];

            $sides[$group['side']]['groups'][] = ['label' => __($group['label']), 'rows' => $rows, ...$sums];

            foreach ($sums as $key => $sum) {
                $sides[$group['side']][$key] += $sum;
            }
        }

        // The result not yet booked to equity (8999/2099) makes the two sides meet.
        $unbooked = [
            'opening' => $sides['assets']['opening'] - $sides['equity']['opening'],
            'change' => $sides['assets']['change'] - $sides['equity']['change'],
            'closing' => $sides['assets']['closing'] - $sides['equity']['closing'],
        ];

        foreach ($unbooked as $key => $amount) {
            $sides['equity'][$key] += $amount;
        }

        return ['sides' => $sides, 'unbooked_result' => $unbooked];
    }

    /**
     * Every row of the period per account, with the running balance
     * (Huvudbok; for one account, Kontoanalys).
     *
     * @return list<array{number: int, name: string, opening: int, closing: int, rows: list<array{date: string, voucher_id: string, reference: string, description: string, debit: int, credit: int, balance: int}>}>
     */
    public function generalLedger(LedgerQuery $query, ?int $accountNumber = null): array
    {
        $opening = $query->openingBalances();
        $accounts = $this->accounts()->when($accountNumber !== null, fn (Collection $accounts) => $accounts->where('number', $accountNumber));

        $rows = $query->rows()
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->when($accountNumber !== null, fn ($builder) => $builder->whereIn('voucher_rows.account_id', $accounts->modelKeys()))
            ->orderBy('vouchers.voucher_date')
            ->orderBy('voucher_series.code')
            ->orderBy('vouchers.number')
            ->orderBy('voucher_rows.id')
            ->get([
                'voucher_rows.account_id', 'voucher_rows.debit_ore', 'voucher_rows.credit_ore', 'voucher_rows.description AS row_text',
                'vouchers.id AS voucher_id', 'vouchers.voucher_date', 'vouchers.number', 'vouchers.description AS voucher_text', 'voucher_series.code AS series_code',
            ])
            ->groupBy('account_id');

        $ledger = [];

        foreach ($accounts as $account) {
            $balance = (int) ($opening[$account->id] ?? 0);
            $start = $balance;
            $lines = [];

            foreach ($rows->get($account->id, []) as $row) {
                /** @var VoucherRow $row */
                $balance += $row->debit_ore->ore - $row->credit_ore->ore;

                $lines[] = [
                    'date' => (string) $row->getAttribute('voucher_date'),
                    'voucher_id' => (string) $row->getAttribute('voucher_id'),
                    'reference' => $row->getAttribute('series_code').$row->getAttribute('number'),
                    'description' => (string) ($row->getAttribute('row_text') ?: $row->getAttribute('voucher_text')),
                    'debit' => $row->debit_ore->ore,
                    'credit' => $row->credit_ore->ore,
                    'balance' => $balance,
                ];
            }

            if ($lines === [] && $start === 0 && $accountNumber === null) {
                continue;
            }

            $ledger[] = ['number' => $account->number, 'name' => $account->name, 'opening' => $start, 'closing' => $balance, 'rows' => $lines];
        }

        return $ledger;
    }

    /**
     * The posted vouchers of the period in series and number order. With a
     * cost centre or project filter, the vouchers with a row for it.
     *
     * @return Collection<int, Voucher>
     */
    public function voucherList(LedgerQuery $query): Collection
    {
        $ids = $query->rows()->distinct()->pluck('vouchers.id');

        return Voucher::query()
            ->whereIn('vouchers.id', $ids)
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->orderBy('voucher_series.code')
            ->orderBy('vouchers.number')
            ->select('vouchers.*')
            ->with(['series', 'rows.account', 'rows.costCenter', 'rows.project'])
            ->get();
    }

    /**
     * The same period one year earlier, inside the previous fiscal year, for
     * comparison; null when there is no previous year.
     */
    public static function previousPeriod(LedgerQuery $query): ?LedgerQuery
    {
        $previous = FiscalYear::query()->whereDate('end_date', $query->fiscalYear->start_date->subDay())->first();

        if (! $previous instanceof FiscalYear) {
            return null;
        }

        $from = self::clamp($query->from->subYear(), $previous);
        $to = self::clamp($query->to->subYear(), $previous);

        return new LedgerQuery($previous, $from, $to, $query->costCenterId, $query->projectId);
    }

    private static function clamp(CarbonImmutable $date, FiscalYear $year): CarbonImmutable
    {
        return $date->max($year->start_date)->min($year->end_date);
    }

    /**
     * @return array<int, int> account id => debit minus credit over the period
     */
    private function periodChange(LedgerQuery $query): array
    {
        return $query->movements()->map(fn (array $movement): int => $movement['debit'] - $movement['credit'])->all();
    }

    /** @return Collection<int, Account> */
    private function accounts(): Collection
    {
        return $this->accounts ??= Account::query()->orderBy('number')->get();
    }

    /** @return Collection<int, Account> */
    private function accountsBetween(int $from, int $to): Collection
    {
        return $this->accounts()->filter(fn (Account $account): bool => $account->number >= $from && $account->number <= $to);
    }
}
