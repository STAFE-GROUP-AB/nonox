<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Accounting\VoucherSource;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingAccounts;
use App\Models\VoucherRow;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

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
 * - any change on 2099, of either sign and whatever the counter accounts,
 *   is the previous year's result moving to Balanserad vinst eller förlust
 *   (e): a profit moved by the disposition of YearEndClosing::dispose or a
 *   plain voucher of the same shape, or a loss year's disposition that
 *   credits 2099; what is left of the previous result on 2099 at the end of
 *   the year is shown there too, as the balance sheet does;
 * - a decrease of free equity whose only counter account is 2898 (Outtagen
 *   vinstutdelning), with the same amount, is a dividend (d); a change on
 *   2093 only (Erhållna aktieägartillskott) a contribution or its repayment
 *   (d); an increase of share capital and share premium only a new issue (d);
 * - a voucher with only equity rows is a transfer (e): to or from the fund
 *   for development expenses (2089, S1-R10, ÅRL 4 kap. 2 § andra stycket and
 *   8 §) on rows of their own, anything else as other transfers;
 * - a correcting voucher (CORRECTION, from CorrectVoucher: the reversal and
 *   the re-booked rows in one voucher) is netted with the voucher it
 *   corrects when that is in the year, so the pair is classified by its
 *   net as the original's kind (a pair with no net equity change drops
 *   out); a correction of an earlier year's voucher that changes equity is
 *   refused (an error correction belongs to row b, below), and a correction
 *   whose original in the year did not touch equity is classified by what it
 *   books.
 * Row b (retroactive application, where NONOX puts the K2-to-K3 transition,
 * A.1 point 5) holds the voucher with source K3_TRANSITION (human decision
 * H7 (a), S1-P12: dated the first day of the first K3 year, against 2091),
 * with any in-year correction of it netted; error corrections of earlier
 * years still block. Row c has no source in NONOX yet (no revaluation, fair
 * value or translation reserve is booked by NONOX).
 *
 * K3 6.3 has no row for other changes, and the rule sheet treats a residual
 * as a blocking error (ADR-059 A.1 point 5): a change that fits none of the
 * rows (own shares on 2094, a repayment of share capital, a dividend mixed
 * with other counter accounts), an opening balance that differs from last
 * year's closing balance, or a post whose rows do not add up refuses the
 * statement with the accounts and vouchers behind it (refusal(),
 * K3EquityStatementRefused). Nothing is ever printed as "Övriga
 * förändringar".
 *
 * TODO(legal-verify): the K2-to-K3 transition in row b rather than c (A.1
 * point 5, the accountant checklist); a new issue and the repayment of a
 * contribution as rows of their own under d; the BAS accounts 2093 and 2898
 * as the marks of a contribution and a dividend (H9/H10, BAS 2026 chart);
 * a previous result left on 2099 printed as "Omföring av föregående års
 * resultat" without a decision of the annual meeting behind it; tax effects
 * recognised in equity (K3 29, 6.3 comment) have no source and block; a
 * correction of an earlier year's error (K3 10, 6.3 b) is refused: row b
 * takes only the K2-to-K3 transition (S1-P12), not yet error corrections.
 *
 * TODO(S1-P12/P10): the columns of the K3 document must come from columns()
 * here: a post that is zero at both ends but moved during the year is in the
 * statement but not in AnnualAccounts::equityColumns().
 *
 * One query (the year's posted rows grouped by voucher and account, kept
 * when the voucher touches equity), whatever the number of vouchers; the rest
 * is read from AnnualAccounts, which has already read the ledger.
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

    /** @var list<array{voucher: string, accounts: list<int>}> corrections of an earlier year's voucher */
    private array $earlierCorrections = [];

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

        foreach ($this->earlierCorrections as $correction) {
            $parts[] = __(':correction och ändrar eget kapital på konto :accounts; rättelse av fel från ett tidigare år hör till punktens rad b, som ännu inte används', ['correction' => $correction['voucher'], 'accounts' => implode(', ', $correction['accounts'])]);
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

        foreach ([...$this->unclassified, ...$this->earlierCorrections] as $voucher) {
            array_push($accounts, ...$voucher['accounts']);
        }

        foreach ($this->unexplained as $column) {
            array_push($accounts, ...$column['accounts']);
        }

        $accounts = array_values(array_unique($accounts));
        sort($accounts);

        return $accounts;
    }

    /**
     * The equity columns of the statement, in order: the balance sheet's
     * equity posts plus any post a voucher moved during the year.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * The BAS accounts of an equity column (2099 for the year's result).
     *
     * @return list<int>
     */
    public static function columnAccounts(string $column): array
    {
        if ($column === 'year_result') {
            return [self::YEAR_RESULT_ACCOUNT];
        }

        $accounts = [];

        foreach (K3Schedule::BALANCE as $line) {
            if ($line['key'] === $column) {
                foreach ($line['ranges'] as [$from, $to]) {
                    array_push($accounts, ...range($from, $to));
                }
            }
        }

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

        foreach (self::withCorrections($this->vouchers()) as $voucher) {
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
     * with its rows summed per account, credit positive. One query: the
     * year's posted rows grouped by voucher and account, with a window over
     * each voucher that marks whether any of its accounts is in equity (no
     * IN subquery, whose semi join was planned badly; S1-P7 review).
     *
     * @return list<array{id: string, references: list<string>, source: string, corrects: ?string, earlier: ?string, rows: array<int, int>}>
     */
    private function vouchers(): array
    {
        $rows = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->where('vouchers.fiscal_year_id', $this->accounts->fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->groupBy('voucher_rows.voucher_id', 'vouchers.source', 'vouchers.number', 'vouchers.corrects_voucher_id', 'voucher_series.code', 'accounts.number')
            ->selectRaw('voucher_rows.voucher_id, vouchers.source, vouchers.number AS voucher_number, vouchers.corrects_voucher_id, voucher_series.code AS series_code, accounts.number AS account_number, SUM(voucher_rows.credit_ore) - SUM(voucher_rows.debit_ore) AS net_credit, BOOL_OR(accounts.number BETWEEN ? AND ?) OVER (PARTITION BY voucher_rows.voucher_id) AS touches_equity', self::EQUITY_ACCOUNTS);

        // The voucher a correction corrects, looked up by its key for the few equity rows
        // only (a lateral join, so the lookup is by primary key whatever the statistics),
        // and never outside the company even if a corrects_voucher_id were corrupt.
        $original = DB::table('vouchers as originals')
            ->join('voucher_series as original_series', 'original_series.id', '=', 'originals.series_id')
            ->join('fiscal_years as original_years', 'original_years.id', '=', 'originals.fiscal_year_id')
            ->whereColumn('originals.id', 'year_rows.corrects_voucher_id')
            ->where('originals.company_id', $this->accounts->fiscalYear->company_id)
            ->selectRaw('originals.fiscal_year_id AS original_year_id, originals.number AS original_number, original_series.code AS original_series_code, original_years.start_date AS original_start, original_years.end_date AS original_end');

        $result = DB::query()
            ->fromSub($rows, 'year_rows')
            ->leftJoinLateral($original, 'original')
            ->where('year_rows.touches_equity', true)
            ->orderBy('year_rows.series_code')
            ->orderBy('year_rows.voucher_number')
            ->orderBy('year_rows.account_number')
            ->select(['year_rows.*', 'original.*'])
            ->get();

        $vouchers = [];

        foreach ($result as $row) {
            $id = (string) $row->voucher_id;
            $vouchers[$id] ??= [
                'id' => $id,
                'references' => [$row->series_code.$row->voucher_number],
                'source' => (string) $row->source,
                'corrects' => $row->corrects_voucher_id === null ? null : (string) $row->corrects_voucher_id,
                'earlier' => self::earlierOriginal($row, $this->accounts->fiscalYear->id),
                'rows' => [],
            ];
            $vouchers[$id]['rows'][(int) $row->account_number] = (int) $row->net_credit;
        }

        return array_values($vouchers);
    }

    /**
     * The voucher an in-year correction corrects, as "A12 från räkenskapsåret
     * 2025", when that voucher is in an earlier fiscal year; else null.
     */
    private static function earlierOriginal(stdClass $row, int $fiscalYearId): ?string
    {
        if ($row->original_year_id === null || (int) $row->original_year_id === $fiscalYearId) {
            return null;
        }

        $start = substr((string) $row->original_start, 0, 4);
        $end = substr((string) $row->original_end, 0, 4);

        return __(':voucher från räkenskapsåret :year', ['voucher' => $row->original_series_code.$row->original_number, 'year' => $start === $end ? $start : "{$start}/{$end}"]);
    }

    /**
     * A correcting voucher (CorrectVoucher writes the reversal and any
     * re-booked rows in one voucher) is netted with the voucher it corrects
     * when both are in the year, along the whole chain of corrections, so the
     * pair is classified by what was finally booked: a pair that leaves no
     * equity change drops out, and a re-booked amount lands on the original's
     * row with its signed net. A correction whose original is in an earlier
     * fiscal year carries that original ("A12 från räkenskapsåret 2025") and
     * is refused by classify() when it changes equity; one whose original is
     * in the year but outside equity is classified alone.
     *
     * @param  list<array{id: string, references: list<string>, source: string, corrects: ?string, earlier: ?string, rows: array<int, int>}>  $vouchers
     * @return list<array{references: list<string>, earlier: ?string, source: string, rows: array<int, int>}>
     */
    private static function withCorrections(array $vouchers): array
    {
        $byId = array_column($vouchers, null, 'id');
        $groups = [];

        foreach ($vouchers as $voucher) {
            $root = $voucher;
            $seen = [];

            while ($root['corrects'] !== null && isset($byId[$root['corrects']]) && ! isset($seen[$root['id']])) {
                $seen[$root['id']] = true;
                $root = $byId[$root['corrects']];
            }

            $key = $root['id'];
            $groups[$key] ??= [
                'references' => [],
                'earlier' => $root['earlier'] === null ? null : (string) __('rättelseverifikation :correction rättar verifikation :original', ['correction' => $root['references'][0], 'original' => $root['earlier']]),
                'source' => $root['source'],
                'rows' => [],
            ];
            $groups[$key]['references'] = [...$groups[$key]['references'], ...$voucher['references']];

            foreach ($voucher['rows'] as $number => $change) {
                $groups[$key]['rows'][$number] = ($groups[$key]['rows'][$number] ?? 0) + $change;
            }
        }

        return array_values($groups);
    }

    /**
     * Puts one voucher's (or one corrected pair's) equity changes on the rows
     * of K3 6.3, or records it as unclassified. Nothing is added unless the
     * whole voucher is classified.
     *
     * @param  array{references: list<string>, earlier: ?string, source: string, rows: array<int, int>}  $voucher
     * @return list<string> the equity columns the voucher touches
     */
    private function classify(array $voucher): array
    {
        $equityAccounts = [];
        $counter = [];
        $touched = [];

        foreach ($voucher['rows'] as $number => $change) {
            $column = self::columnOf($number);

            if ($column !== null) {
                $touched[] = $column;
            }

            if ($change === 0) {
                continue;
            }

            if ($column === null) {
                $counter[$number] = $change;

                continue;
            }

            $equityAccounts[$number] = $change;
        }

        if ($equityAccounts === [] || $this->booksTheYearsResult($counter)) {
            return $touched;
        }

        // The K2-to-K3 transition (K3 35.11, S1-P12): retroactive application, row b.
        if ($voucher['source'] === VoucherSource::K3Transition->value) {
            $retroactive = [];

            foreach ($equityAccounts as $number => $change) {
                $column = (string) self::columnOf($number);
                $retroactive[$column] = ($retroactive[$column] ?? 0) + $change;
            }

            $this->add('retroactive', array_filter($retroactive));

            return $touched;
        }

        // A correction of an error from an earlier year belongs to 6.3 b (S1-P12, H7), which no
        // voucher reaches yet: it is refused, never shown as an owner transaction.
        if ($voucher['earlier'] !== null) {
            $accounts = array_keys($equityAccounts);
            sort($accounts);
            $this->earlierCorrections[] = ['voucher' => $voucher['earlier'], 'accounts' => $accounts];

            return $touched;
        }

        $equity = [];

        foreach ($equityAccounts as $number => $change) {
            $column = (string) self::columnOf($number);
            $equity[$column] = ($equity[$column] ?? 0) + $change;
        }

        $equity = array_filter($equity);
        $plan = [];

        // Any change on 2099 is the previous year's result moving to (or back
        // from) Balanserad vinst eller förlust (6.3 e), whatever its sign and
        // counter accounts: a loss year's disposition credits 2099.
        $previousResult = $equity['year_result'] ?? 0;

        if ($previousResult !== 0) {
            $plan[] = ['prior_result_transfer', ['year_result' => $previousResult, 'retained' => -$previousResult]];
            unset($equity['year_result']);
            $equity['retained'] = ($equity['retained'] ?? 0) + $previousResult;
            $equity = array_filter($equity);
            $touched[] = 'retained';
        }

        if ($equity !== [] && $counter === []) {
            $fund = $equity['development_fund'] ?? 0;

            if ($fund !== 0) {
                $plan[] = [$fund > 0 ? 'to_development_fund' : 'from_development_fund', ['development_fund' => $fund, 'retained' => -$fund]];
                unset($equity['development_fund']);
                $equity['retained'] = ($equity['retained'] ?? 0) + $fund;
                $equity = array_filter($equity);
            }

            if ($equity !== []) {
                $plan[] = ['equity_transfers', $equity];
            }

            $equity = [];
        }

        if ($equity !== []) {
            $accounts = array_values(array_filter(array_keys($equityAccounts), fn (int $number): bool => $number !== self::YEAR_RESULT_ACCOUNT));
            $kind = self::ownerTransaction($equity, $accounts, $counter);

            if ($kind === null) {
                $this->unclassified[] = ['voucher' => implode(', ', $voucher['references']), 'accounts' => self::accountsBehind($equityAccounts, $equity)];

                return $touched;
            }

            $plan[] = [$kind, $equity];
        }

        foreach ($plan as [$row, $amounts]) {
            $this->add($row, $amounts);
        }

        return $touched;
    }

    /**
     * The equity accounts of an unclassified voucher in the posts left after
     * the previous year's result moved, or else all of its equity accounts,
     * so a refusal always names an account.
     *
     * @param  array<int, int>  $equityAccounts
     * @param  array<string, int>  $equity
     * @return list<int>
     */
    private static function accountsBehind(array $equityAccounts, array $equity): array
    {
        $accounts = array_values(array_filter(array_keys($equityAccounts), fn (int $number): bool => $number !== self::YEAR_RESULT_ACCOUNT && isset($equity[(string) self::columnOf($number)])));
        $accounts = $accounts === [] ? array_keys($equityAccounts) : $accounts;
        sort($accounts);

        return $accounts;
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
     * equity is, or null when it is none of them. A dividend is a decrease
     * of free equity whose only counter account is 2898, with the same amount.
     *
     * @param  array<string, int>  $equity  the change per column, without the previous year's result
     * @param  list<int>  $accounts  the equity accounts with a change, without 2099
     * @param  array<int, int>  $counter  the change per counter account
     */
    private static function ownerTransaction(array $equity, array $accounts, array $counter): ?string
    {
        $total = array_sum($equity);
        $positive = array_filter($equity, fn (int $amount): bool => $amount > 0);

        if ($positive !== [] && count($positive) !== count($equity)) {
            return null;
        }

        $columns = array_keys($equity);

        if ($total < 0 && $counter === [ClosingAccounts::DIVIDEND => -$total] && array_diff($columns, self::DIVIDEND_COLUMNS) === []) {
            return 'dividend';
        }

        if ($accounts === [self::CONTRIBUTION_ACCOUNT]) {
            return $total > 0 ? 'shareholder_contribution' : 'contribution_repayment';
        }

        if ($total > 0 && array_diff($columns, self::NEW_ISSUE_COLUMNS) === []) {
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

        foreach ([...$this->unclassified, ...$this->earlierCorrections] as $voucher) {
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

            // The balance sheet shows what 2099 holds beyond the year's result under retained earnings.
            $inColumn = array_values(array_filter($this->accounts->numbers(), fn (int $number): bool => self::columnOf($number) === $column || ($column === 'retained' && $number === self::YEAR_RESULT_ACCOUNT)));

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
