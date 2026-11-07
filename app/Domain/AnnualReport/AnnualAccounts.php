<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Models\YearEndEntry;
use Illuminate\Support\Collection;

/**
 * The figures of an annual report for one fiscal year in the layout of a
 * schedule (K2Schedule by default, K3Schedule for a K3 report), with the
 * previous year beside them: income statement, balance sheet, the multi-year
 * overview, changes in equity, the free equity at the meeting's disposal
 * and the note on fixed assets. Amounts are öre, shown the way the report
 * shows them: income and assets positive, costs negative, equity and
 * liabilities positive. Three queries per year and one for the purchase
 * accounts marked as goods for resale.
 */
final class AnnualAccounts
{
    /** @var array<int, array{opening: int, debit: int, credit: int}> by account number */
    private array $current;

    /** @var ?array<int, array{opening: int, debit: int, credit: int}> */
    private ?array $previous;

    /** @var array<int, int> purchase accounts marked as goods for resale (K2Schedule::PURCHASES) */
    private array $resale;

    /**
     * @param  class-string<K2Schedule>|class-string<K3Schedule>  $schedule  the layout of the statements (ADR-059 A.3: K2 unless a K3 report asks for K3)
     */
    public function __construct(
        public readonly FiscalYear $fiscalYear,
        public readonly ?FiscalYear $previousYear,
        public readonly string $schedule = K2Schedule::class,
    ) {
        $this->current = self::ledger($fiscalYear);
        $this->previous = $previousYear === null ? null : self::ledger($previousYear);
        $this->resale = Account::query()
            ->whereBetween('number', $schedule::PURCHASES)
            ->where('sru_code', $schedule::GOODS_FOR_RESALE_SRU)
            ->pluck('number')->map(fn (mixed $number): int => (int) $number)->values()->all();
    }

    /**
     * @param  class-string<K2Schedule>|class-string<K3Schedule>  $schedule
     */
    public static function for(FiscalYear $fiscalYear, string $schedule = K2Schedule::class): self
    {
        $previous = FiscalYear::query()->whereDate('end_date', $fiscalYear->start_date->subDay()->toDateString())->first();

        return new self($fiscalYear, $previous, $schedule);
    }

    /**
     * @return list<array{key: string, label: string, amount: int, previous: ?int, subtotal: bool}>
     */
    public function incomeStatement(): array
    {
        $lines = [];
        $values = ['current' => [], 'previous' => []];

        foreach ($this->schedule::INCOME as $line) {
            $amount = -$this->lineMovement($this->current, $line['ranges'], $line['resale'] ?? null);
            $previous = $this->previous === null ? null : -$this->lineMovement($this->previous, $line['ranges'], $line['resale'] ?? null);
            $values['current'][$line['key']] = $amount;
            $values['previous'][$line['key']] = $previous ?? 0;

            if ($amount !== 0 || ($previous ?? 0) !== 0) {
                $lines[] = ['key' => $line['key'], 'label' => __($line['label']), 'amount' => $amount, 'previous' => $previous, 'subtotal' => false];
            }

            $subtotal = $this->schedule::INCOME_SUBTOTALS[$line['key']] ?? null;

            if ($subtotal !== null) {
                $sum = array_sum(array_intersect_key($values['current'], array_flip($subtotal['lines'])));
                $before = array_sum(array_intersect_key($values['previous'], array_flip($subtotal['lines'])));
                $values['current'][$subtotal['key']] = $sum;
                $values['previous'][$subtotal['key']] = $before;
                $lines[] = ['key' => $subtotal['key'], 'label' => __($subtotal['label']), 'amount' => $sum, 'previous' => $this->previous === null ? null : $before, 'subtotal' => true];
            }
        }

        return $lines;
    }

    public function yearResult(): int
    {
        return -$this->movement($this->current, 3000, 8989);
    }

    public function netSales(): int
    {
        return -$this->movement($this->current, 3000, 3799);
    }

    public function resultAfterFinancialItems(): int
    {
        return -$this->movement($this->current, 3000, 8799);
    }

    /**
     * The balance sheet at the end of the year and the year before. The
     * year's result line is the income statement's result, whether or not
     * it has been booked to 2099 yet; anything else on 2099 (an earlier
     * result not yet disposed of) is shown as retained earnings.
     *
     * @return array{sections: list<array{key: string, label: string, lines: list<array{key: string, label: string, amount: int, previous: int}>, amount: int, previous: int}>, assets: int, assets_previous: int, equity_liabilities: int, equity_liabilities_previous: int, equity: int, equity_previous: int}
     */
    public function balanceSheet(): array
    {
        $current = $this->columns($this->current, opening: false);
        $previous = $this->previous === null ? $this->columns($this->current, opening: true) : $this->columns($this->previous, opening: false);
        $sections = [];

        foreach ($this->schedule::SECTIONS as $section => $label) {
            $lines = [];

            foreach (array_filter($this->schedule::BALANCE, fn (array $line): bool => $line['section'] === $section) as $line) {
                $this->addLine($lines, $line['key'], __($line['label']), $current[$line['key']], $previous[$line['key']]);
            }

            if ($section === 'free') {
                $this->addLine($lines, 'year_result', __('Årets resultat'), $current['year_result'], $previous['year_result']);
            }

            if ($lines !== []) {
                $sections[] = [
                    'key' => $section,
                    'label' => __($label),
                    'lines' => $lines,
                    'amount' => array_sum(array_column($lines, 'amount')),
                    'previous' => array_sum(array_column($lines, 'previous')),
                ];
            }
        }

        $total = fn (array $keys, string $field): int => (int) collect($sections)->whereIn('key', $keys)->sum($field);
        $equityKeys = ['restricted', 'free'];
        $liabilityKeys = ['restricted', 'free', 'untaxed', 'provisions', 'long_term', 'short_term'];

        return [
            'sections' => $sections,
            'assets' => $total($this->schedule::ASSET_SECTIONS, 'amount'),
            'assets_previous' => $total($this->schedule::ASSET_SECTIONS, 'previous'),
            'equity_liabilities' => $total($liabilityKeys, 'amount'),
            'equity_liabilities_previous' => $total($liabilityKeys, 'previous'),
            'equity' => $total($equityKeys, 'amount'),
            'equity_previous' => $total($equityKeys, 'previous'),
        ];
    }

    /**
     * The multi-year overview (flerårsöversikt): net sales, result after
     * financial items and soliditet for this year and up to three earlier
     * years in the books, newest first.
     *
     * TODO(legal-verify): how many years K2 requires, and whether a broken
     * or extended first year needs a note.
     *
     * @return list<array{label: string, start: string, end: string, net_sales: int, after_financial: int, solidity: ?float}>
     */
    public function multiYear(int $years = 4): array
    {
        $rows = [];
        $accounts = $this;

        for ($i = 0; $i < $years; $i++) {
            $rows[] = ['label' => $accounts->fiscalYear->label(), 'start' => $accounts->fiscalYear->start_date->toDateString(), 'end' => $accounts->fiscalYear->end_date->toDateString(), 'net_sales' => $accounts->netSales(), 'after_financial' => $accounts->resultAfterFinancialItems(), 'solidity' => $accounts->solidity()];

            if ($accounts->previousYear === null) {
                break;
            }

            $accounts = self::for($accounts->previousYear, $this->schedule);
        }

        return $rows;
    }

    /**
     * The pairs of consecutive years in the multi-year overview whose net
     * sales differ by more than 30 %, newest first. A change from 0 counts.
     * BFNAR 2016:10 and 2025:2 p. 5.5: such a variation must be commented
     * on in the förvaltningsberättelse (#93).
     *
     * TODO(legal-verify): whether "mellan åren" means every pair shown in
     * the overview or only this year against the previous one.
     *
     * @param  list<array{label: string, start: string, end: string, net_sales: int, after_financial: int, solidity: ?float}>|null  $rows  multiYear(), if already computed
     * @return list<array{from: string, to: string, from_amount: int, to_amount: int}>
     */
    public function netSalesVariations(?array $rows = null): array
    {
        $rows ??= $this->multiYear();
        $variations = [];

        for ($i = 0; $i + 1 < count($rows); $i++) {
            $newer = $rows[$i]['net_sales'];
            $older = $rows[$i + 1]['net_sales'];
            $varies = $older === 0 ? $newer !== 0 : abs($newer - $older) * 100 > 30 * abs($older);

            if ($varies) {
                $variations[] = ['from' => $rows[$i + 1]['label'], 'to' => $rows[$i]['label'], 'from_amount' => $older, 'to_amount' => $newer];
            }
        }

        return $variations;
    }

    /** The free equity at the annual meeting's disposal: retained earnings and the year's result. */
    public function freeEquity(): int
    {
        $free = collect($this->balanceSheet()['sections'])->firstWhere('key', 'free');

        return (int) ($free['amount'] ?? 0);
    }

    /**
     * Soliditet: adjusted equity (equity plus untaxed reserves less deferred
     * tax) over total assets, in per cent with one decimal.
     *
     * TODO(legal-verify): the deferred tax rate on untaxed reserves (20,6 %).
     */
    public function solidity(): ?float
    {
        $sheet = $this->balanceSheet();

        if ($sheet['assets'] === 0) {
            return null;
        }

        $untaxed = (int) (collect($sheet['sections'])->firstWhere('key', 'untaxed')['amount'] ?? 0);

        return round(($sheet['equity'] + $untaxed * 0.794) / $sheet['assets'] * 100, 1);
    }

    /**
     * The columns of the specification of changes in equity: the equity
     * lines of the balance sheet (aktiekapital, reservfond, balanserat
     * resultat, årets resultat) that have an amount in either year, or that
     * the disposition of the previous year's result moves.
     *
     * @return list<array{key: string, label: string}>
     */
    public function equityColumns(): array
    {
        return array_map(fn (array $column): array => ['key' => $column['key'], 'label' => $column['label']], array_values($this->equityLines()));
    }

    /**
     * The specification of changes in equity compared with the previous
     * year's balance sheet (ÅRL 6 kap. 2 § andra stycket): the amounts at
     * the start of the year, the disposition decided by the annual meeting
     * (dividend, carried forward), other changes (e.g. a new issue or a
     * shareholder contribution), the year's result and the amounts at the
     * end of the year, per column of equityColumns() and in total. The rows
     * add up: start + changes = end, per column (#90).
     *
     * @return list<array{key: string, label: string, amounts: array<string, int>, total: int}>
     */
    public function equityChanges(): array
    {
        $lines = $this->equityLines();
        $keys = array_keys($lines);
        $row = function (string $key, string $label, array $amounts) use ($keys): array {
            $amounts = array_map(fn (string $column): int => (int) ($amounts[$column] ?? 0), array_combine($keys, $keys));

            return ['key' => $key, 'label' => $label, 'amounts' => $amounts, 'total' => array_sum($amounts)];
        };

        $opening = $row('opening', __('Belopp vid årets ingång'), array_map(fn (array $line): int => $line['previous'], $lines));
        $closing = $row('closing', __('Belopp vid årets utgång'), array_map(fn (array $line): int => $line['amount'], $lines));
        $changes = [];
        $disposition = $this->disposition();

        if ($disposition !== null) {
            [$disposed, $dividend] = $disposition;

            if ($dividend !== 0) {
                $changes[] = $row('dividend', __('Utdelning'), ['year_result' => -$dividend]);
            }

            $changes[] = $row('carried_forward', __('Balanseras i ny räkning'), ['retained' => $disposed - $dividend, 'year_result' => -($disposed - $dividend)]);
        }

        $changes[] = $row('year_result', __('Årets resultat'), ['year_result' => $closing['amounts']['year_result'] ?? 0]);

        $other = [];

        foreach ($keys as $key) {
            $other[$key] = $closing['amounts'][$key] - $opening['amounts'][$key] - array_sum(array_map(fn (array $change): int => $change['amounts'][$key], $changes));
        }

        if (array_filter($other) !== []) {
            $changes[] = $row('other', __('Övriga förändringar'), $other);
        }

        return [$opening, ...$changes, $closing];
    }

    /**
     * The equity lines of the balance sheet by key, with retained earnings
     * and the year's result always present when the year has a disposition.
     *
     * @return array<string, array{key: string, label: string, amount: int, previous: int}>
     */
    private function equityLines(): array
    {
        $lines = [];

        foreach ($this->balanceSheet()['sections'] as $section) {
            if (in_array($section['key'], ['restricted', 'free'], true)) {
                foreach ($section['lines'] as $line) {
                    $lines[$line['key']] = $line;
                }
            }
        }

        $retained = $this->schedule === K3Schedule::class ? __('Balanserad vinst eller förlust') : __('Balanserat resultat');
        $lines['retained'] ??= ['key' => 'retained', 'label' => $retained, 'amount' => 0, 'previous' => 0];
        $lines['year_result'] ??= ['key' => 'year_result', 'label' => __('Årets resultat'), 'amount' => 0, 'previous' => 0];

        $order = array_flip($this->equityOrder());
        uksort($lines, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $lines;
    }

    /**
     * The order of the equity columns: the schedule's bound and free equity
     * lines with the year's result right after retained earnings. For K2
     * this is share_capital, reserve, retained, year_result, as before.
     *
     * @return list<string>
     */
    private function equityOrder(): array
    {
        $order = [];

        foreach ($this->schedule::BALANCE as $line) {
            if (in_array($line['section'], ['restricted', 'free'], true)) {
                $order[] = $line['key'];
            }

            if ($line['key'] === 'retained') {
                $order[] = 'year_result';
            }
        }

        return $order;
    }

    /**
     * The previous year's result disposed of by the annual meeting in this
     * year, and the dividend: [result, dividend] in öre, or null.
     *
     * @return ?array{0: int, 1: int}
     */
    private function disposition(): ?array
    {
        if ($this->previousYear === null) {
            return null;
        }

        $entry = YearEndEntry::query()
            ->where('fiscal_year_id', $this->previousYear->id)
            ->where('type', YearEndEntry::DISPOSITION)
            ->whereIn('voucher_id', Voucher::query()->select('id')->where('fiscal_year_id', $this->fiscalYear->id))
            ->first();

        return $entry === null ? null : [$entry->amount_ore->ore, $entry->dividend_ore->ore];
    }

    /**
     * The note on fixed assets per balance sheet line: acquisition values
     * (accounts not ending in 9) and accumulated depreciation (ending in 9).
     *
     * @return list<array{key: string, label: string, acquisition: array{opening: int, additions: int, disposals: int, closing: int}, depreciation: array{opening: int, disposals: int, charge: int, closing: int}, book_value: int}>
     */
    public function fixedAssetNotes(): array
    {
        $notes = [];

        foreach (array_filter($this->schedule::BALANCE, fn (array $line): bool => in_array($line['key'], $this->schedule::FIXED_ASSET_LINES, true)) as $line) {
            $cost = ['opening' => 0, 'additions' => 0, 'disposals' => 0, 'closing' => 0];
            $depreciation = ['opening' => 0, 'disposals' => 0, 'charge' => 0, 'closing' => 0];

            foreach ($this->current as $number => $amounts) {
                if (! self::inRanges($number, $line['ranges'])) {
                    continue;
                }

                $closing = $amounts['opening'] + $amounts['debit'] - $amounts['credit'];

                if ($number % 10 === 9) {
                    $depreciation['opening'] -= $amounts['opening'];
                    $depreciation['disposals'] += $amounts['debit'];
                    $depreciation['charge'] += $amounts['credit'];
                    $depreciation['closing'] -= $closing;

                    continue;
                }

                $cost['opening'] += $amounts['opening'];
                $cost['additions'] += $amounts['debit'];
                $cost['disposals'] += $amounts['credit'];
                $cost['closing'] += $closing;
            }

            if ($cost['opening'] !== 0 || $cost['closing'] !== 0 || $cost['additions'] !== 0) {
                $notes[] = ['key' => $line['key'], 'label' => __($line['label']), 'acquisition' => $cost, 'depreciation' => $depreciation, 'book_value' => $cost['closing'] - $depreciation['closing']];
            }
        }

        return $notes;
    }

    /**
     * The balance sheet amount per line key at the end of a year, or at its
     * start (the opening balances). Equity and liabilities are positive.
     *
     * @param  array<int, array{opening: int, debit: int, credit: int}>  $ledger
     * @return array<string, int>
     */
    private function columns(array $ledger, bool $opening): array
    {
        $at = fn (int $from, int $to): int => $opening ? $this->sum($ledger, $from, $to, opening: true) : $this->closing($ledger, $from, $to);
        $columns = [];

        foreach ($this->schedule::BALANCE as $line) {
            $sign = in_array($line['section'], $this->schedule::ASSET_SECTIONS, true) ? 1 : -1;
            $columns[$line['key']] = $sign * array_sum(array_map(fn (array $range): int => $at($range[0], $range[1]), $line['ranges']));
        }

        // 2099 together with what the income statement has not yet moved there.
        $onResultAccount = -$at(2099, 2099) - ($opening ? 0 : $this->movement($ledger, 3000, 8999));
        $yearResult = $opening ? $onResultAccount : -$this->movement($ledger, 3000, 8989);
        $columns['year_result'] = $yearResult;
        $columns['retained'] = ($columns['retained'] ?? 0) + $onResultAccount - $yearResult;

        return $columns;
    }

    /**
     * @param  list<array{key: string, label: string, amount: int, previous: int}>  $lines
     */
    private function addLine(array &$lines, string $key, string $label, int $amount, int $previous): void
    {
        if ($amount !== 0 || $previous !== 0) {
            $lines[] = ['key' => $key, 'label' => $label, 'amount' => $amount, 'previous' => $previous];
        }
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private static function inRanges(int $number, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($number >= $from && $number <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Debit minus credit for the year on the accounts in the ranges, with
     * the purchase accounts for goods for resale moved out of or into them.
     *
     * @param  list<array{0: int, 1: int}>  $ranges
     * @param  'include'|'exclude'|null  $resale
     */
    public function movementOfRanges(array $ranges, ?string $resale = null): int
    {
        return $this->lineMovement($this->current, $ranges, $resale);
    }

    /**
     * @param  array<int, array{opening: int, debit: int, credit: int}>  $ledger
     * @param  list<array{0: int, 1: int}>  $ranges
     * @param  'include'|'exclude'|null  $resale
     */
    private function lineMovement(array $ledger, array $ranges, ?string $resale): int
    {
        $total = 0;

        foreach ($ledger as $number => $amounts) {
            $inRanges = self::inRanges($number, $ranges);
            $isResale = in_array($number, $this->resale, true);

            if (($inRanges && ! ($isResale && $resale === 'exclude')) || ($isResale && $resale === 'include')) {
                $total += $amounts['debit'] - $amounts['credit'];
            }
        }

        return $total;
    }

    /**
     * The account numbers with an opening balance or a posting in the year.
     *
     * @return list<int>
     */
    public function numbers(): array
    {
        return array_keys($this->current);
    }

    /** Debit minus credit for the year on the accounts in the range. */
    public function movementOf(int $from, int $to): int
    {
        return $this->movement($this->current, $from, $to);
    }

    /** The opening balance of the year on the accounts in the range, debit positive. */
    public function openingOf(int $from, int $to): int
    {
        return $this->sum($this->current, $from, $to, opening: true);
    }

    /** The balance at the end of the year on the accounts in the range, debit positive. */
    public function closingOf(int $from, int $to): int
    {
        return $this->closing($this->current, $from, $to);
    }

    /** @param  array<int, array{opening: int, debit: int, credit: int}>  $ledger */
    private function movement(array $ledger, int $from, int $to): int
    {
        return $this->sum($ledger, $from, $to, opening: false);
    }

    /** @param  array<int, array{opening: int, debit: int, credit: int}>  $ledger */
    private function closing(array $ledger, int $from, int $to): int
    {
        return $this->sum($ledger, $from, $to, opening: true) + $this->movement($ledger, $from, $to);
    }

    /** @param  array<int, array{opening: int, debit: int, credit: int}>  $ledger */
    private function sum(array $ledger, int $from, int $to, bool $opening): int
    {
        $total = 0;

        foreach ($ledger as $number => $amounts) {
            if ($number >= $from && $number <= $to) {
                $total += $opening ? $amounts['opening'] : $amounts['debit'] - $amounts['credit'];
            }
        }

        return $total;
    }

    /**
     * Opening balance, debit and credit per account number for a year.
     *
     * @return array<int, array{opening: int, debit: int, credit: int}>
     */
    private static function ledger(FiscalYear $fiscalYear): array
    {
        /** @var Collection<int, int> $numbers */
        $numbers = Account::query()->pluck('number', 'id')->map(fn (mixed $number): int => (int) $number);
        $ledger = [];

        foreach (OpeningBalance::query()->where('fiscal_year_id', $fiscalYear->id)->get(['account_id', 'amount_ore']) as $balance) {
            $number = (int) $numbers[$balance->account_id];
            $ledger[$number] = ['opening' => $balance->amount_ore->ore, 'debit' => 0, 'credit' => 0];
        }

        $movements = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore) AS debit, SUM(voucher_rows.credit_ore) AS credit')
            ->get();

        foreach ($movements as $row) {
            $number = (int) $numbers[(int) $row->account_id];
            $ledger[$number] ??= ['opening' => 0, 'debit' => 0, 'credit' => 0];
            $ledger[$number]['debit'] = (int) $row->getAttribute('debit');
            $ledger[$number]['credit'] = (int) $row->getAttribute('credit');
        }

        return $ledger;
    }
}
