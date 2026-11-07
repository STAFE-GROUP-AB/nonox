<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingAccounts;
use App\Models\VoucherRow;
use LogicException;

/**
 * The specification of changes in equity of a K3 annual report (ADR-059
 * A.1 point 5, A.4 S1-P7; rule sheet S1-R8.3, S1-R10, S1-R7).
 *
 * ÅRL 6 kap. 2 § andra stycket (Lag 2024:347), quoted in K3 chapter 6:
 * "Andra företag än handelsbolag och enskilda näringsidkare ska i
 * förvaltningsberättelsen eller i egen räkning specificera förändringar i
 * eget kapital jämfört med föregående års balansräkning." It applies to a
 * mindre aktiebolag too.
 *
 * K3 6.3 (vl12-1-k3-kons20251215, p. 55, read 2026-11-07): "Specifikationen
 * enligt 6 kap. 2 § årsredovisningslagen (1995:1554) ska visa dels
 * räkenskapsårets förändringar i varje post i eget kapital, dels följande
 * uppgifter: a) Räkenskapsårets resultat. b) Effekterna av retroaktiv
 * tillämpning och effekten av rättelse av fel enligt kapitel 10.
 * c) Förändringar i redovisade värden på tillgångar och skulder som
 * redovisats direkt mot eget kapital med varje slag av förändring separat
 * specificerad. d) Transaktioner med ägare i deras egenskap av ägare, med
 * tillskott, utdelningar och andra värdeöverföringar separat angivna.
 * e) Omföringar mellan poster inom eget kapital."
 * Its comment (p. 56): "Exempel på uppgifter enligt punkt 6.3 e är utgifter
 * för eget utvecklingsarbete i aktiebolag och ekonomiska föreningar".
 * K3 6.4 second paragraph: a contribution received from an owner without
 * issuing shares "ska redovisas som en ökning av eget kapital"; 6.5: its
 * repayment "som en minskning av eget kapital när beslut om återbetalning har
 * fattats".
 *
 * The columns are the equity posts of the K3 balance sheet (K3Schedule,
 * through AnnualAccounts), the year's result after Balanserad vinst eller
 * förlust. The rows come from the posted vouchers of the year that touch an
 * equity account (BAS 2080–2099), each read with its counter accounts:
 * - the year's result booked to equity (8990–8999 against equity, the
 *   CLOSING voucher of YearEndClosing::bookResult) is row a, not a change;
 * - a debit on 2099 (the previous year's result, moved by the disposition of
 *   YearEndClosing::dispose or a plain voucher of the same shape) is a
 *   transfer to Balanserad vinst eller förlust (e); what is left of the
 *   previous result on 2099 is shown there too, as the balance sheet does;
 * - a decrease of free equity against 2898 (Outtagen vinstutdelning) is a
 *   dividend (d); a change on 2093 only (Erhållna aktieägartillskott) a
 *   contribution or its repayment (d); an increase of share capital and share
 *   premium only a new issue (d);
 * - a voucher with only equity rows is a transfer (e): to or from the fund
 *   for development expenses (2089, S1-R10, ÅRL 4 kap. 2 § andra stycket and
 *   8 §) on rows of their own, anything else as other transfers;
 * - a correcting voucher (CORRECTION) lands on the row of what it corrects.
 * Row b (retroactive application, where NONOX puts the K2-to-K3 transition,
 * A.1 point 5) is reserved: the transition voucher's source is human
 * decision H7 (S1-P12), so no voucher reaches the row yet and a transition
 * booked today blocks as below. Row c has no source in NONOX yet (no
 * revaluation, fair value or translation reserve is booked by NONOX).
 *
 * K3 6.3 has no row for other changes, and the rule sheet treats a residual
 * as a blocking error (ADR-059 A.1 point 5): a change that fits none of the
 * rows, an opening balance that differs from last year's closing balance, or
 * a post whose rows do not add up refuses the statement with the accounts
 * and vouchers behind it (refusal(), K3EquityStatementRefused). Nothing is
 * ever printed as "Övriga förändringar".
 *
 * TODO(legal-verify): the K2-to-K3 transition in row b rather than c (A.1
 * point 5, the accountant checklist); a new issue and the repayment of a
 * contribution as rows of their own under d; the BAS accounts 2093 and 2898
 * as the marks of a contribution and a dividend (H9/H10, BAS 2026 chart).
 *
 * One query (the equity vouchers of the year with their rows, grouped by
 * voucher and account), whatever the number of vouchers; the rest is read
 * from AnnualAccounts, which has already read the ledger.
 */
final class K3EquityStatement
{
    /** BAS 2080–2099: eget kapital in an aktiebolag or ekonomisk förening. */
    public const array EQUITY_ACCOUNTS = [2080, 2099];

    /** BAS 2099 Årets resultat: the column year_result (AnnualAccounts). */
    public const int YEAR_RESULT_ACCOUNT = 2099;

    /** BAS 2093 Erhållna aktieägartillskott (K3 6.4, 6.5). */
    public const int CONTRIBUTION_ACCOUNT = 2093;

    /** BAS 8990–8999: the year's result booked to equity (YearEndClosing::bookResult). */
    public const array RESULT_BOOKING_ACCOUNTS = [8990, 8999];

    /** The posts a dividend may be paid from: fritt eget kapital (ABL 17 kap. 3 §). */
    public const array DIVIDEND_COLUMNS = ['retained', 'free_share_premium'];

    /** The posts a new issue credits: aktiekapital and överkursfond. */
    public const array NEW_ISSUE_COLUMNS = ['share_capital', 'unregistered_share_capital', 'bound_share_premium', 'free_share_premium'];

    /** The rows in the order they are shown. */
    public const array LABELS = [
        'opening' => 'Belopp vid årets ingång',
        'retroactive' => 'Effekter av retroaktiv tillämpning och rättelse av fel',
        'prior_result_transfer' => 'Omföring av föregående års resultat',
        'dividend' => 'Utdelning',
        'shareholder_contribution' => 'Erhållna aktieägartillskott',
        'contribution_repayment' => 'Återbetalning av aktieägartillskott',
        'new_issue' => 'Nyemission',
        'to_development_fund' => 'Omföring till fond för utvecklingsutgifter',
        'from_development_fund' => 'Omföring från fond för utvecklingsutgifter',
        'equity_transfers' => 'Omföringar mellan poster inom eget kapital',
        'year_result' => 'Årets resultat',
        'closing' => 'Belopp vid årets utgång',
    ];

    /** The point of K3 6.3 each row answers (null: the amounts at the start and the end). */
    public const array POINTS = [
        'opening' => null,
        'retroactive' => 'b',
        'prior_result_transfer' => 'e',
        'dividend' => 'd',
        'shareholder_contribution' => 'd',
        'contribution_repayment' => 'd',
        'new_issue' => 'd',
        'to_development_fund' => 'e',
        'from_development_fund' => 'e',
        'equity_transfers' => 'e',
        'year_result' => 'a',
        'closing' => null,
    ];

    /** @var list<string> */
    private array $columns;

    /** @var array<string, array<string, int>> row key => column => amount */
    private array $changes = [];

    /** @var array<string, int> */
    private array $opening = [];

    /** @var array<string, int> */
    private array $closing = [];

    /** @var list<array{voucher: string, accounts: list<int>}> */
    private array $unclassified = [];

    /** @var list<int> */
    private array $openingDifferences = [];

    /** @var list<array{column: string, accounts: list<int>}> */
    private array $unexplained = [];

    private function __construct(private readonly AnnualAccounts $accounts)
    {
        if ($accounts->schedule !== K3Schedule::class) {
            throw new LogicException('The K3 equity statement needs the K3 schedule.');
        }
    }

    public static function of(AnnualAccounts $accounts): self
    {
        $statement = new self($accounts);
        $statement->build();

        return $statement;
    }

    /**
     * The rows in the shape of AnnualAccounts::equityChanges(): opening, the
     * changes with an amount, the year's result, closing. Every column adds
     * up: opening + changes = closing.
     *
     * @return list<array{key: string, label: string, amounts: array<string, int>, total: int}>
     *
     * @throws K3EquityStatementRefused when a change fits no row of K3 6.3
     */
    public function rows(): array
    {
        $reason = $this->refusal();

        if ($reason !== null) {
            throw new K3EquityStatementRefused($reason, $this->residualAccounts());
        }

        $rows = [];

        foreach (array_keys(self::LABELS) as $key) {
            $amounts = match ($key) {
                'opening' => $this->opening,
                'closing' => $this->closing,
                default => $this->changes[$key] ?? null,
            };

            if ($amounts === null || ($key !== 'year_result' && ! in_array($key, ['opening', 'closing'], true) && array_filter($amounts) === [])) {
                continue;
            }

            $amounts = array_map(fn (string $column): int => $amounts[$column] ?? 0, array_combine($this->columns, $this->columns));
            $rows[] = ['key' => $key, 'label' => __(self::LABELS[$key]), 'amounts' => $amounts, 'total' => array_sum($amounts)];
        }

        return $rows;
    }

    /** Why the statement cannot be drawn up under K3 6.3, in Swedish, or null. */
    public function refusal(): ?string
    {
        $parts = [];

        foreach ($this->unclassified as $voucher) {
            $parts[] = __('verifikation :voucher ändrar eget kapital på konto :accounts utan att höra till någon av punktens rader', ['voucher' => $voucher['voucher'], 'accounts' => implode(', ', $voucher['accounts'])]);
        }

        if ($this->openingDifferences !== []) {
            $parts[] = __('den ingående balansen på konto :accounts skiljer sig från föregående års utgående balans', ['accounts' => implode(', ', $this->openingDifferences)]);
        }

        foreach ($this->unexplained as $column) {
            $parts[] = __('posten :post går inte ihop med årets verifikationer (konto :accounts)', ['post' => self::columnLabel($column['column']), 'accounts' => implode(', ', $column['accounts'])]);
        }

        if ($parts === []) {
            return null;
        }

        return __('Förändringarna i eget kapital kan inte specificeras enligt K3 punkt 6.3: :reasons. Punkten har ingen rad för övriga förändringar, så årsredovisningen kan inte upprättas förrän det är rättat.', ['reasons' => implode('; ', $parts)]);
    }

    /**
     * The BAS accounts behind a refusal, ascending (empty when there is none).
     *
     * @return list<int>
     */
    public function residualAccounts(): array
    {
        $accounts = [...$this->openingDifferences];

        foreach ($this->unclassified as $voucher) {
            array_push($accounts, ...$voucher['accounts']);
        }

        foreach ($this->unexplained as $column) {
            array_push($accounts, ...$column['accounts']);
        }

        $accounts = array_values(array_unique($accounts));
        sort($accounts);

        return $accounts;
    }

    /** The label of an equity column (a K3 balance sheet line, or the year's result). */
    public static function columnLabel(string $column): string
    {
        if ($column === 'year_result') {
            return __('Årets resultat');
        }

        foreach (K3Schedule::BALANCE as $line) {
            if ($line['key'] === $column) {
                return __($line['label']);
            }
        }

        return $column;
    }

    /** The equity column an account belongs to, or null outside equity. */
    public static function columnOf(int $number): ?string
    {
        if ($number === self::YEAR_RESULT_ACCOUNT) {
            return 'year_result';
        }

        foreach (K3Schedule::BALANCE as $line) {
            if (! in_array($line['section'], ['restricted', 'free'], true)) {
                continue;
            }

            foreach ($line['ranges'] as [$from, $to]) {
                if ($number >= $from && $number <= $to) {
                    return $line['key'];
                }
            }
        }

        return null;
    }

    private function build(): void
    {
        foreach ($this->accounts->balanceSheet()['sections'] as $section) {
            if (! in_array($section['key'], ['restricted', 'free'], true)) {
                continue;
            }

            foreach ($section['lines'] as $line) {
                $this->opening[$line['key']] = $line['previous'];
                $this->closing[$line['key']] = $line['amount'];
            }
        }

        $touched = array_column($this->accounts->equityColumns(), 'key');

        foreach ($this->vouchers() as $voucher) {
            $touched = [...$touched, ...$this->classify($voucher)];
        }

        $this->add('year_result', ['year_result' => $this->accounts->yearResult()]);
        $this->columns = self::ordered($touched);
        $this->undisposedPreviousResult();
        $this->checkOpeningBalances();
        $this->checkColumns();
    }

    /**
     * The posted vouchers of the year with a row on an equity account, each
     * with its rows summed per account, credit positive.
     *
     * @return list<array{reference: string, source: string, rows: array<int, int>}>
     */
    private function vouchers(): array
    {
        $fiscalYearId = $this->accounts->fiscalYear->id;

        $equityVoucherIds = VoucherRow::query()
            ->join('vouchers as equity_vouchers', 'equity_vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts as equity_accounts', 'equity_accounts.id', '=', 'voucher_rows.account_id')
            ->where('equity_vouchers.fiscal_year_id', $fiscalYearId)
            ->whereBetween('equity_accounts.number', self::EQUITY_ACCOUNTS)
            ->select('voucher_rows.voucher_id');

        $rows = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->where('vouchers.fiscal_year_id', $fiscalYearId)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('voucher_rows.voucher_id', $equityVoucherIds)
            ->groupBy('voucher_rows.voucher_id', 'vouchers.source', 'vouchers.number', 'voucher_series.code', 'accounts.number')
            ->orderBy('voucher_series.code')
            ->orderBy('vouchers.number')
            ->orderBy('accounts.number')
            ->selectRaw('voucher_rows.voucher_id, vouchers.source, vouchers.number AS voucher_number, voucher_series.code AS series_code, accounts.number AS account_number, SUM(voucher_rows.credit_ore) - SUM(voucher_rows.debit_ore) AS net_credit')
            ->get();

        $vouchers = [];

        foreach ($rows as $row) {
            $id = (string) $row->getAttribute('voucher_id');
            $vouchers[$id] ??= [
                'reference' => $row->getAttribute('series_code').$row->getAttribute('voucher_number'),
                'source' => (string) $row->getAttribute('source'),
                'rows' => [],
            ];
            $vouchers[$id]['rows'][(int) $row->getAttribute('account_number')] = (int) $row->getAttribute('net_credit');
        }

        return array_values($vouchers);
    }

    /**
     * Puts one voucher's equity changes on the rows of K3 6.3, or records it
     * as unclassified.
     *
     * @param  array{reference: string, source: string, rows: array<int, int>}  $voucher
     * @return list<string> the equity columns the voucher touches
     */
    private function classify(array $voucher): array
    {
        $equity = [];
        $equityAccounts = [];
        $counter = [];

        foreach ($voucher['rows'] as $number => $change) {
            $column = self::columnOf($number);

            if ($change === 0) {
                continue;
            }

            if ($column === null) {
                $counter[$number] = $change;

                continue;
            }

            $equity[$column] = ($equity[$column] ?? 0) + $change;
            $equityAccounts[$number] = $column;
        }

        $touched = array_keys($equity);
        $equity = array_filter($equity);

        if ($equity === [] || $this->booksTheYearsResult($counter)) {
            return $touched;
        }

        // The previous year's result leaves 2099 for Balanserad vinst eller förlust (6.3 e).
        $previousResult = $equity['year_result'] ?? 0;

        if ($previousResult < 0 || ($previousResult > 0 && $counter === [])) {
            $this->add('prior_result_transfer', ['year_result' => $previousResult, 'retained' => -$previousResult]);
            unset($equity['year_result']);
            $equity['retained'] = ($equity['retained'] ?? 0) + $previousResult;
            $touched[] = 'retained';
            $equity = array_filter($equity);
        }

        if ($equity === []) {
            return $touched;
        }

        if ($counter === []) {
            $fund = $equity['development_fund'] ?? 0;

            if ($fund !== 0) {
                $this->add($fund > 0 ? 'to_development_fund' : 'from_development_fund', ['development_fund' => $fund, 'retained' => -$fund]);
                unset($equity['development_fund']);
                $equity['retained'] = ($equity['retained'] ?? 0) + $fund;
                $touched[] = 'retained';
                $equity = array_filter($equity);
            }

            if ($equity !== []) {
                $this->add('equity_transfers', $equity);
            }

            return $touched;
        }

        $kind = $this->ownerTransaction($equity, $equityAccounts, $counter, $voucher['source']);

        if ($kind === null) {
            $accounts = array_keys(array_filter($equityAccounts, fn (string $column): bool => isset($equity[$column])));
            sort($accounts);
            $this->unclassified[] = ['voucher' => $voucher['reference'], 'accounts' => $accounts];

            return $touched;
        }

        $this->add($kind, $equity);

        return $touched;
    }

    /** @param  array<int, int>  $counter */
    private function booksTheYearsResult(array $counter): bool
    {
        if ($counter === []) {
            return false;
        }

        foreach (array_keys($counter) as $number) {
            if ($number < self::RESULT_BOOKING_ACCOUNTS[0] || $number > self::RESULT_BOOKING_ACCOUNTS[1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The owner transaction (6.3 d) a voucher with counter accounts outside
     * equity is, or null when it is none of them.
     *
     * @param  array<string, int>  $equity  the change per column, without the previous year's result
     * @param  array<int, string>  $equityAccounts  equity account => column
     * @param  array<int, int>  $counter  the change per counter account
     */
    private function ownerTransaction(array $equity, array $equityAccounts, array $counter, string $source): ?string
    {
        $positive = array_filter($equity, fn (int $amount): bool => $amount > 0);

        if ($positive !== [] && count($positive) !== count($equity)) {
            return null;
        }

        // A correcting voucher reverses what it corrects: it goes on the same row.
        $increase = ($positive !== []) !== ($source === VoucherSource::Correction->value);
        $columns = array_keys($equity);
        $accounts = array_keys(array_filter($equityAccounts, fn (string $column): bool => isset($equity[$column])));

        if (! $increase && isset($counter[ClosingAccounts::DIVIDEND]) && array_diff($columns, self::DIVIDEND_COLUMNS) === []) {
            return 'dividend';
        }

        if ($accounts === [self::CONTRIBUTION_ACCOUNT]) {
            return $increase ? 'shareholder_contribution' : 'contribution_repayment';
        }

        if ($increase && array_diff($columns, self::NEW_ISSUE_COLUMNS) === []) {
            return 'new_issue';
        }

        return null;
    }

    /**
     * What is left of the previous year's result on 2099 after the year's
     * vouchers: the balance sheet shows it under Balanserad vinst eller
     * förlust (AnnualAccounts::columns), so the statement transfers it there.
     */
    private function undisposedPreviousResult(): void
    {
        $left = ($this->opening['year_result'] ?? 0) - ($this->closing['year_result'] ?? 0);

        foreach ($this->changes as $amounts) {
            $left += $amounts['year_result'] ?? 0;
        }

        if ($left !== 0) {
            $this->add('prior_result_transfer', ['year_result' => -$left, 'retained' => $left]);
        }
    }

    /**
     * The statement starts from last year's balance sheet (ÅRL 6 kap. 2 §):
     * an opening balance that differs from it is a change no row explains.
     * Retained earnings and the year's result are compared together, since
     * the rollover moves the result between them.
     */
    private function checkOpeningBalances(): void
    {
        if ($this->accounts->previousYear === null) {
            return;
        }

        $fromLedger = $this->accounts->openingColumns();
        $differs = [];

        foreach ($this->columns as $column) {
            if ($column === 'year_result') {
                continue;
            }

            $difference = ($fromLedger[$column] ?? 0) - ($this->opening[$column] ?? 0);

            if ($column === 'retained') {
                $difference += ($fromLedger['year_result'] ?? 0) - ($this->opening['year_result'] ?? 0);
            }

            if ($difference !== 0) {
                $differs[] = $column;
            }
        }

        if ($differs === []) {
            return;
        }

        $accounts = [];

        foreach ($this->accounts->openingDifferences(self::EQUITY_ACCOUNTS[0], self::EQUITY_ACCOUNTS[1]) as $number => $difference) {
            $column = self::columnOf($number);
            $column = $column === 'year_result' ? 'retained' : $column;

            if ($difference !== 0 && in_array($column, $differs, true) && $number !== self::YEAR_RESULT_ACCOUNT) {
                $accounts[] = $number;
            }
        }

        $this->openingDifferences = $accounts === [] ? [self::YEAR_RESULT_ACCOUNT] : $accounts;
        sort($this->openingDifferences);
    }

    /** Every column must add up: opening + changes = closing. A column that does not is a residual. */
    private function checkColumns(): void
    {
        $explained = [...$this->openingDifferences];

        foreach ($this->unclassified as $voucher) {
            array_push($explained, ...$voucher['accounts']);
        }

        foreach ($this->columns as $column) {
            $sum = $this->opening[$column] ?? 0;

            foreach ($this->changes as $amounts) {
                $sum += $amounts[$column] ?? 0;
            }

            if ($sum === ($this->closing[$column] ?? 0)) {
                continue;
            }

            $inColumn = array_values(array_filter($this->accounts->numbers(), fn (int $number): bool => self::columnOf($number) === $column));

            if (array_intersect($inColumn, $explained) !== []) {
                continue;
            }

            sort($inColumn);
            $this->unexplained[] = ['column' => $column, 'accounts' => $inColumn];
        }
    }

    /** @param  array<string, int>  $amounts */
    private function add(string $row, array $amounts): void
    {
        foreach ($amounts as $column => $amount) {
            $this->changes[$row][$column] = ($this->changes[$row][$column] ?? 0) + $amount;
        }
    }

    /**
     * The equity columns in the order of the K3 balance sheet, the year's
     * result right after Balanserad vinst eller förlust.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function ordered(array $columns): array
    {
        $order = [];

        foreach (K3Schedule::BALANCE as $line) {
            if (in_array($line['section'], ['restricted', 'free'], true)) {
                $order[] = $line['key'];
            }

            if ($line['key'] === 'retained') {
                $order[] = 'year_result';
            }
        }

        return array_values(array_filter($order, fn (string $column): bool => in_array($column, $columns, true)));
    }
}
