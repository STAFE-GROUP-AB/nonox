<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use LogicException;

/**
 * The cash flow statement of a K3 annual report, indirect method (ADR-059
 * A.4 S1-P8, addendum point 3; rule sheet S1-R2, S1-R2.1).
 *
 * When: ÅRL 2 kap. 1 § andra stycket (Lag 2022:1028): "I årsredovisningen
 * för ett större företag eller en bostadsrättsförening ska det även ingå en
 * kassaflödesanalys." For a mindre företag it is the company's choice, the
 * policy `cash_flow_statement` (K3Policies); K3 7.1: "Kapitlet ska även
 * tillämpas på en frivilligt upprättad kassaflödesanalys." forYear() applies
 * the policy and the size (K3CompanySize): `none` gives no statement for a
 * mindre företag and is refused for a större företag and for a company whose
 * size is undetermined; the direct method, which K3 7.8 allows, is not built
 * and is refused.
 *
 * How: K3 7.9: "Tillämpas indirekt metod, ska nettot av in- och utbetalningar
 * i den löpande verksamheten beräknas och redovisas genom att resultatet
 * justeras för a) icke-kassaflödespåverkande poster och alla övriga poster
 * vilkas kassaflödeseffekt hänförs till investeringsverksamheten eller
 * finansieringsverksamheten, samt b) periodens förändringar av varulager,
 * kundfordringar och leverantörsskulder samt förändringar av andra
 * rörelsefordringar och rörelseskulder." 7.16: "Utbetald utdelning ska
 * hänföras till finansieringsverksamheten." 7.17: "Betalningar som avser
 * inkomstskatter ska redovisas separat och hänföras till den löpande
 * verksamheten, …".
 *
 * The statement is pure balance-change arithmetic per account, not a
 * classification of vouchers: every account's movement in the year (debit
 * minus credit, from AnnualAccounts, which has already read the ledger)
 * belongs to one category of CashFlowAccounts, and each line is the sum of
 * its accounts' movements with the cash sign (inflow positive). Since every
 * posted voucher balances, the lines add up to the change in likvida medel
 * by construction; the statement checks it anyway and refuses when it does
 * not. Corrections therefore need no handling of their own (unlike
 * K3EquityStatement): a correcting voucher changes the same balances as the
 * voucher it corrects, and only the net change is read. The starting point
 * is "Resultat efter finansiella poster" (F-S1-a assumption A7), so
 * appropriations, tax and the booking of the year's result are not in it.
 *
 * Three categories carry pairs that must net to zero because neither side is
 * cash: depreciation against accumulated depreciation, deferred tax against
 * its provision or asset, and appropriations against untaxed reserves. When
 * they do not (a disposal, depreciation booked straight against the cost
 * account) the statement is refused, as it is for an account no category
 * covers and for a movement whose cash effect NONOX cannot yet place
 * (CashFlowAccounts::NOT_BUILT). The owner transactions (dividends,
 * contributions, new issues) come from K3EquityStatement, the change of
 * unpaid dividends (2898) turning a decided dividend into a paid one; an
 * equity statement that is refused refuses this one too.
 *
 * TODO(legal-verify): the line labels are NONOX's own (K3 gives no
 * wording); interest paid and received (7.15) and the changes of loans and
 * fixed assets are netted per year, not shown gross; a currency difference
 * on likvida medel is not shown as its own reconciling item; notes 7.18–7.20
 * (större företag) are S1-P13. See CashFlowAccounts for the account
 * mapping.
 *
 * Queries: one for the equity statement; with the previous year beside it,
 * AnnualAccounts for that year and its equity statement. The count does not
 * depend on the number of vouchers.
 */
final class CashFlowStatement
{
    /**
     * The lines in the order they are shown: label, section, the K3 point the
     * line answers, whether it is a sum, and whether it is shown when zero.
     *
     * @var array<string, array{label: string, section: string, point: string, subtotal: bool, always: bool}>
     */
    public const array LINES = [
        'resultat_efter_finansiella_poster' => ['label' => 'Resultat efter finansiella poster', 'section' => 'operating', 'point' => '7.9', 'subtotal' => false, 'always' => true],
        'justering_avskrivningar' => ['label' => 'Justering för avskrivningar och nedskrivningar', 'section' => 'operating', 'point' => '7.9 a', 'subtotal' => false, 'always' => false],
        'justering_avsattningar' => ['label' => 'Justering för förändring av avsättningar', 'section' => 'operating', 'point' => '7.9 a', 'subtotal' => false, 'always' => false],
        'betald_inkomstskatt' => ['label' => 'Betald inkomstskatt', 'section' => 'operating', 'point' => '7.17', 'subtotal' => false, 'always' => false],
        'kassaflode_fore_forandring_av_rorelsekapital' => ['label' => 'Kassaflöde från den löpande verksamheten före förändring av rörelsekapital', 'section' => 'operating', 'point' => '7.9', 'subtotal' => true, 'always' => true],
        'forandring_varulager' => ['label' => 'Förändring av varulager', 'section' => 'operating', 'point' => '7.9 b', 'subtotal' => false, 'always' => false],
        'forandring_rorelsefordringar' => ['label' => 'Förändring av rörelsefordringar', 'section' => 'operating', 'point' => '7.9 b', 'subtotal' => false, 'always' => false],
        'forandring_rorelseskulder' => ['label' => 'Förändring av rörelseskulder', 'section' => 'operating', 'point' => '7.9 b', 'subtotal' => false, 'always' => false],
        'kassaflode_fran_den_lopande_verksamheten' => ['label' => 'Kassaflöde från den löpande verksamheten', 'section' => 'operating', 'point' => '7.8', 'subtotal' => true, 'always' => true],
        'forvarv_av_immateriella_anlaggningstillgangar' => ['label' => 'Förvärv av immateriella anläggningstillgångar', 'section' => 'investing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'forvarv_av_materiella_anlaggningstillgangar' => ['label' => 'Förvärv av materiella anläggningstillgångar', 'section' => 'investing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'forvarv_av_finansiella_anlaggningstillgangar' => ['label' => 'Förvärv av finansiella anläggningstillgångar', 'section' => 'investing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'avyttring_av_finansiella_anlaggningstillgangar' => ['label' => 'Avyttring och minskning av finansiella anläggningstillgångar', 'section' => 'investing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'forandring_kortfristiga_placeringar' => ['label' => 'Förändring av kortfristiga placeringar', 'section' => 'investing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'kassaflode_fran_investeringsverksamheten' => ['label' => 'Kassaflöde från investeringsverksamheten', 'section' => 'investing', 'point' => '7.2', 'subtotal' => true, 'always' => true],
        'nyemission' => ['label' => 'Nyemission', 'section' => 'financing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'erhallna_aktieagartillskott' => ['label' => 'Erhållna aktieägartillskott', 'section' => 'financing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'aterbetalning_av_aktieagartillskott' => ['label' => 'Återbetalning av aktieägartillskott', 'section' => 'financing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'upptagna_lan' => ['label' => 'Upptagna lån', 'section' => 'financing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'amortering_av_lan' => ['label' => 'Amortering av lån', 'section' => 'financing', 'point' => '7.2', 'subtotal' => false, 'always' => false],
        'utbetald_utdelning' => ['label' => 'Utbetald utdelning', 'section' => 'financing', 'point' => '7.16', 'subtotal' => false, 'always' => false],
        'kassaflode_fran_finansieringsverksamheten' => ['label' => 'Kassaflöde från finansieringsverksamheten', 'section' => 'financing', 'point' => '7.2', 'subtotal' => true, 'always' => true],
        'arets_kassaflode' => ['label' => 'Årets kassaflöde', 'section' => 'cash', 'point' => '7.2', 'subtotal' => true, 'always' => true],
        'likvida_medel_vid_arets_borjan' => ['label' => 'Likvida medel vid årets början', 'section' => 'cash', 'point' => '7.4', 'subtotal' => false, 'always' => true],
        'likvida_medel_vid_arets_slut' => ['label' => 'Likvida medel vid årets slut', 'section' => 'cash', 'point' => '7.4', 'subtotal' => false, 'always' => true],
        'tillaggsupplysning_betald_ranta' => ['label' => 'Betald ränta', 'section' => 'supplementary', 'point' => '7.15', 'subtotal' => false, 'always' => false],
        'tillaggsupplysning_erhallen_ranta' => ['label' => 'Erhållen ränta', 'section' => 'supplementary', 'point' => '7.15', 'subtotal' => false, 'always' => false],
    ];

    /**
     * Each sum and the lines it adds, in an order where a sum comes after
     * the sums it uses.
     *
     * @var array<string, list<string>>
     */
    public const array SUBTOTALS = [
        'kassaflode_fore_forandring_av_rorelsekapital' => ['resultat_efter_finansiella_poster', 'justering_avskrivningar', 'justering_avsattningar', 'betald_inkomstskatt'],
        'kassaflode_fran_den_lopande_verksamheten' => ['kassaflode_fore_forandring_av_rorelsekapital', 'forandring_varulager', 'forandring_rorelsefordringar', 'forandring_rorelseskulder'],
        'kassaflode_fran_investeringsverksamheten' => ['forvarv_av_immateriella_anlaggningstillgangar', 'forvarv_av_materiella_anlaggningstillgangar', 'forvarv_av_finansiella_anlaggningstillgangar', 'avyttring_av_finansiella_anlaggningstillgangar', 'forandring_kortfristiga_placeringar'],
        'kassaflode_fran_finansieringsverksamheten' => ['nyemission', 'erhallna_aktieagartillskott', 'aterbetalning_av_aktieagartillskott', 'upptagna_lan', 'amortering_av_lan', 'utbetald_utdelning'],
        'arets_kassaflode' => ['kassaflode_fran_den_lopande_verksamheten', 'kassaflode_fran_investeringsverksamheten', 'kassaflode_fran_finansieringsverksamheten'],
    ];

    /** BAS 2898 Outtagen vinstutdelning: a decided dividend not yet paid. */
    public const int UNPAID_DIVIDEND_ACCOUNT = 2898;

    /** @var array<string, int> */
    private array $amounts = [];

    /** @var list<string> the reasons the statement cannot be drawn up, in Swedish */
    private array $reasons = [];

    /** @var list<int> accounts no category covers, or whose movement NONOX cannot yet place */
    private array $unclassified = [];

    /** @var list<int> every account behind a refusal */
    private array $refusedAccounts = [];

    private ?self $previous = null;

    private function __construct(private readonly AnnualAccounts $accounts)
    {
        if ($accounts->schedule !== K3Schedule::class) {
            throw new LogicException('The K3 cash flow statement needs the K3 schedule.');
        }
    }

    /**
     * The statement of the year, with the previous year beside it when that
     * year is in the books (and $withPrevious).
     */
    public static function of(AnnualAccounts $accounts, bool $withPrevious = true): self
    {
        $statement = new self($accounts);
        $statement->build();

        if ($withPrevious && $accounts->previousYear !== null) {
            $statement->previous = self::of(AnnualAccounts::for($accounts->previousYear, K3Schedule::class), false);
            $reason = $statement->previous->refusal();

            if ($reason !== null) {
                $statement->reasons[] = (string) __('jämförelseårets kassaflödesanalys kan inte upprättas (:reason)', ['reason' => rtrim($reason, '.')]);
                $statement->addAccounts($statement->previous->refusedAccounts);
            }
        }

        return $statement;
    }

    /**
     * The statement the report has for the year under its policy and size, or
     * null when it has none (a mindre företag with the policy `none`).
     *
     * @throws CashFlowStatementRefused when the policy is missing, is the direct method, or is `none` where ÅRL 2 kap. 1 § requires a statement
     */
    public static function forYear(AnnualAccounts $accounts, K3Policies $policies, K3SizeClass $size): ?self
    {
        $row = $policies->row(K3PolicyKey::CashFlowStatement);
        $value = $row?->policyValue();

        if ($row !== null && $value === null) {
            throw new CashFlowStatementRefused($row->value === 'direct'
                ? (string) __('Kassaflödesanalys med direkt metod stöds inte i NONOX. K3 punkt 7.8 tillåter både direkt och indirekt metod; välj indirekt metod.')
                : (string) __('Principen :value för kassaflödesanalysen känns inte igen. Välj ingen kassaflödesanalys eller indirekt metod.', ['value' => $row->value]));
        }

        if ($value === null) {
            throw new CashFlowStatementRefused((string) __('Ingen princip för kassaflödesanalysen har valts för räkenskapsåret. Välj ingen kassaflödesanalys eller indirekt metod.'));
        }

        if ($value === K3CashFlowStatement::None) {
            return match ($size) {
                K3SizeClass::Mindre => null,
                K3SizeClass::Storre => throw new CashFlowStatementRefused((string) __('Ett större företag ska ha en kassaflödesanalys i årsredovisningen (årsredovisningslagen 2 kap. 1 §).')),
                K3SizeClass::Undetermined => throw new CashFlowStatementRefused((string) __('Företagets storlek går inte att avgöra, och ett större företag ska ha en kassaflödesanalys i årsredovisningen (årsredovisningslagen 2 kap. 1 §). Avgör storleken eller välj indirekt metod.')),
            };
        }

        return self::of($accounts);
    }

    /**
     * Every line by key, öre, inflow positive; zero for a line with nothing.
     *
     * @return array<string, int>
     *
     * @throws CashFlowStatementRefused
     */
    public function amounts(): array
    {
        $this->refuseIfNeeded();

        return $this->amounts;
    }

    /**
     * The lines as the report shows them: every sum and the amounts of
     * likvida medel always, any other line when it has an amount this year
     * or last year. previous is null when the previous year is not in the
     * books.
     *
     * @return list<array{key: string, label: string, section: string, point: string, subtotal: bool, amount: int, previous: ?int}>
     *
     * @throws CashFlowStatementRefused
     */
    public function rows(): array
    {
        $this->refuseIfNeeded();
        $previous = $this->previous?->amounts();
        $rows = [];

        foreach (self::LINES as $key => $line) {
            $amount = $this->amounts[$key];
            $before = $previous === null ? null : $previous[$key];

            if (! $line['always'] && $amount === 0 && ($before ?? 0) === 0) {
                continue;
            }

            $rows[] = ['key' => $key, 'label' => (string) __($line['label']), 'section' => $line['section'], 'point' => $line['point'], 'subtotal' => $line['subtotal'], 'amount' => $amount, 'previous' => $before];
        }

        return $rows;
    }

    /** Why the statement cannot be drawn up under K3 chapter 7, in Swedish, or null. */
    public function refusal(): ?string
    {
        if ($this->reasons === []) {
            return null;
        }

        return (string) __('Kassaflödesanalysen kan inte upprättas enligt K3 kapitel 7: :reasons. Inga belopp förs till en rad för övriga poster, så årsredovisningen kan inte tas fram förrän det är rättat.', ['reasons' => implode('; ', $this->reasons)]);
    }

    /**
     * The accounts with a movement that no category covers or whose
     * movement NONOX cannot yet place, ascending (spec Behaviour 4).
     *
     * @return list<int>
     */
    public function unclassifiedAccounts(): array
    {
        $accounts = $this->unclassified;
        sort($accounts);

        return $accounts;
    }

    /**
     * Every BAS account behind a refusal, ascending.
     *
     * @return list<int>
     */
    public function refusedAccounts(): array
    {
        return $this->refusedAccounts;
    }

    private function refuseIfNeeded(): void
    {
        $reason = $this->refusal();

        if ($reason !== null) {
            throw new CashFlowStatementRefused($reason, $this->refusedAccounts);
        }
    }

    private function build(): void
    {
        /** @var array<string, int> $sum */
        $sum = [];
        /** @var array<string, list<int>> $members */
        $members = [];
        /** @var array<string, list<int>> $notBuilt */
        $notBuilt = [];
        /** @var array<int, int> $cash the movement per account, cash sign (credit positive) */
        $cash = [];

        foreach ($this->accounts->numbers() as $number) {
            $movement = $this->accounts->movementOf($number, $number);

            if ($movement === 0) {
                continue;
            }

            $cash[$number] = -$movement;
            $category = CashFlowAccounts::categoryOf($number);

            if ($category === null) {
                $this->unclassified[] = $number;
                $reason = CashFlowAccounts::notBuilt($number);

                if ($reason !== null) {
                    $notBuilt[$reason][] = $number;
                }

                continue;
            }

            $sum[$category] = ($sum[$category] ?? 0) - $movement;
            $members[$category][] = $number;
        }

        $of = fn (string $category): int => $sum[$category] ?? 0;
        $financial = $of(CashFlowAccounts::FINANCIAL_ASSETS);
        $loans = $of(CashFlowAccounts::LOANS);
        $owners = $this->ownerTransactions($cash[self::UNPAID_DIVIDEND_ACCOUNT] ?? 0, $of(CashFlowAccounts::EQUITY), $members[CashFlowAccounts::EQUITY] ?? []);

        $this->amounts = [
            'resultat_efter_finansiella_poster' => $this->accounts->resultAfterFinancialItems(),
            'justering_avskrivningar' => -$of(CashFlowAccounts::DEPRECIATION),
            'justering_avsattningar' => $of(CashFlowAccounts::PROVISIONS),
            'betald_inkomstskatt' => $of(CashFlowAccounts::INCOME_TAX),
            'forandring_varulager' => $of(CashFlowAccounts::INVENTORY),
            'forandring_rorelsefordringar' => $of(CashFlowAccounts::RECEIVABLES),
            'forandring_rorelseskulder' => $of(CashFlowAccounts::PAYABLES),
            'forvarv_av_immateriella_anlaggningstillgangar' => $of(CashFlowAccounts::INTANGIBLE),
            'forvarv_av_materiella_anlaggningstillgangar' => $of(CashFlowAccounts::TANGIBLE),
            'forvarv_av_finansiella_anlaggningstillgangar' => min($financial, 0),
            'avyttring_av_finansiella_anlaggningstillgangar' => max($financial, 0),
            'forandring_kortfristiga_placeringar' => $of(CashFlowAccounts::SHORT_INVESTMENTS),
            ...$owners,
            'upptagna_lan' => max($loans, 0),
            'amortering_av_lan' => min($loans, 0),
            'likvida_medel_vid_arets_borjan' => $this->accounts->openingOf(1900, 1999),
            'likvida_medel_vid_arets_slut' => $this->accounts->closingOf(1900, 1999),
            'tillaggsupplysning_betald_ranta' => -self::cashOf($cash, CashFlowAccounts::INTEREST_EXPENSE) - self::cashOf($cash, CashFlowAccounts::ACCRUED_INTEREST_EXPENSE),
            'tillaggsupplysning_erhallen_ranta' => self::cashOf($cash, CashFlowAccounts::INTEREST_INCOME) + self::cashOf($cash, CashFlowAccounts::ACCRUED_INTEREST_INCOME),
        ];

        $this->amounts = self::withSubtotals(array_merge(array_fill_keys(array_keys(self::LINES), 0), $this->amounts));

        $this->checkUnclassified($notBuilt);
        $this->checkPairs($sum, $members);
        $this->checkDisposals($cash, $members);
        $this->checkCash();
        $this->checkReconciliation();
    }

    /**
     * The financing lines with the owners (K3 7.16 for dividends) from the
     * equity statement: a dividend decided in the year less the increase of
     * unpaid dividends on 2898 is the dividend paid. The equity accounts'
     * movement (without 2898) must equal the owner transactions, since the
     * year's result and the transfers between equity posts are no cash.
     *
     * @param  list<int>  $equityAccounts
     * @return array{nyemission: int, erhallna_aktieagartillskott: int, aterbetalning_av_aktieagartillskott: int, utbetald_utdelning: int}
     */
    private function ownerTransactions(int $unpaidDividends, int $equityMovement, array $equityAccounts): array
    {
        $lines = ['nyemission' => 0, 'erhallna_aktieagartillskott' => 0, 'aterbetalning_av_aktieagartillskott' => 0, 'utbetald_utdelning' => 0];
        $statement = K3EquityStatement::of($this->accounts);
        $reason = $statement->refusal();

        if ($reason !== null) {
            $this->reasons[] = (string) __('förändringarna i eget kapital kan inte specificeras (:reason)', ['reason' => rtrim($reason, '.')]);
            $this->addAccounts($statement->residualAccounts());

            return $lines;
        }

        $totals = array_column($statement->rows(), 'total', 'key');
        $lines['nyemission'] = (int) ($totals['new_issue'] ?? 0);
        $lines['erhallna_aktieagartillskott'] = (int) ($totals['shareholder_contribution'] ?? 0);
        $lines['aterbetalning_av_aktieagartillskott'] = (int) ($totals['contribution_repayment'] ?? 0);
        $dividends = (int) ($totals['dividend'] ?? 0);
        $lines['utbetald_utdelning'] = $dividends + $unpaidDividends;

        if ($equityMovement - $unpaidDividends !== $lines['nyemission'] + $lines['erhallna_aktieagartillskott'] + $lines['aterbetalning_av_aktieagartillskott'] + $dividends) {
            $accounts = array_values(array_filter($equityAccounts, fn (int $number): bool => $number !== self::UNPAID_DIVIDEND_ACCOUNT));
            $this->reasons[] = (string) __('förändringen av eget kapital på konto :accounts motsvarar inte utdelningar, tillskott och nyemissioner enligt specifikationen av förändringar i eget kapital', ['accounts' => self::list($accounts)]);
            $this->addAccounts($accounts);
        }

        return $lines;
    }

    /** @param  array<string, list<int>>  $notBuilt */
    private function checkUnclassified(array $notBuilt): void
    {
        $placed = array_merge(...array_values($notBuilt ?: [[]]));
        $plain = array_values(array_diff($this->unclassified, $placed));

        if ($plain !== []) {
            $this->reasons[] = (string) __('konto :accounts har rörelser under året men är inte klassificerat som likvida medel, löpande verksamhet, investeringsverksamhet eller finansieringsverksamhet', ['accounts' => self::list($plain)]);
        }

        $texts = [
            'disposal' => 'konto :accounts visar resultat vid avyttring av anläggningstillgångar, och försäljningslikviden kan ännu inte skiljas från vinsten eller förlusten (K3 punkt 7.9 a)',
            'financial' => 'konto :accounts är finansiella poster som inte påverkar kassaflödet eller vars kassaflöde hör till investeringsverksamheten (avyttring, nedskrivning, värdeförändring, resultatandel eller aktiverad ränta), och de kan ännu inte justeras (K3 punkt 7.9 a)',
            'group_contribution' => 'konto :accounts är koncernbidrag, som ännu inte kan placeras i kassaflödesanalysen',
        ];

        foreach ($texts as $reason => $text) {
            if (isset($notBuilt[$reason])) {
                $this->reasons[] = (string) __($text, ['accounts' => self::list($notBuilt[$reason])]);
            }
        }

        $this->addAccounts($this->unclassified);
    }

    /**
     * Depreciation against accumulated depreciation, deferred tax against its
     * provision or asset, appropriations against untaxed reserves: neither
     * side is cash, so each pair nets to zero in the year.
     *
     * @param  array<string, int>  $sum
     * @param  array<string, list<int>>  $members
     */
    private function checkPairs(array $sum, array $members): void
    {
        $pairs = [
            'konto :accounts: årets avskrivningar och nedskrivningar motsvarar inte förändringen av de ackumulerade avskrivningarna och nedskrivningarna; avskrivningar ska bokföras mot ett konto för ackumulerade avskrivningar, och en avyttring eller utrangering kan ännu inte visas' => [CashFlowAccounts::DEPRECIATION, CashFlowAccounts::ACCUMULATED],
            'konto :accounts: årets uppskjutna skatt motsvarar inte förändringen av uppskjuten skatteskuld och skattefordran' => [CashFlowAccounts::DEFERRED_TAX],
            'konto :accounts: bokslutsdispositionerna motsvarar inte förändringen av de obeskattade reserverna' => [CashFlowAccounts::UNTAXED_RESERVES],
        ];

        foreach ($pairs as $text => $categories) {
            $net = 0;
            $accounts = [];

            foreach ($categories as $category) {
                $net += $sum[$category] ?? 0;
                array_push($accounts, ...($members[$category] ?? []));
            }

            if ($net !== 0) {
                $this->reasons[] = (string) __($text, ['accounts' => self::list($accounts)]);
                $this->addAccounts($accounts);
            }
        }
    }

    /**
     * Intangible and tangible fixed assets can only grow in the statement:
     * a decrease of their cost accounts is a disposal, whose proceeds NONOX
     * cannot yet show.
     *
     * @param  array<int, int>  $cash
     * @param  array<string, list<int>>  $members
     */
    private function checkDisposals(array $cash, array $members): void
    {
        foreach ([CashFlowAccounts::INTANGIBLE, CashFlowAccounts::TANGIBLE] as $category) {
            $accounts = $members[$category] ?? [];
            $net = array_sum(array_map(fn (int $number): int => $cash[$number], $accounts));

            if ($net > 0) {
                $decreased = array_values(array_filter($accounts, fn (int $number): bool => $cash[$number] > 0));
                $this->reasons[] = (string) __('anskaffningsvärdet på konto :accounts har minskat under året, och avyttring och utrangering av anläggningstillgångar kan ännu inte visas', ['accounts' => self::list($decreased)]);
                $this->addAccounts($decreased);
            }
        }
    }

    /**
     * Likvida medel: no bank account in overdraft (K3 7.4 kommentar: a used
     * overdraft is financing, booked on 2330 or 2480), and the opening
     * balance equal to last year's closing balance.
     */
    private function checkCash(): void
    {
        $negative = [];

        foreach ($this->accounts->numbers() as $number) {
            if (CashFlowAccounts::categoryOf($number) !== CashFlowAccounts::CASH) {
                continue;
            }

            if ($this->accounts->openingOf($number, $number) < 0 || $this->accounts->closingOf($number, $number) < 0) {
                $negative[] = $number;
            }
        }

        if ($negative !== []) {
            $this->reasons[] = (string) __('konto :accounts har ett negativt saldo; en utnyttjad checkräkningskredit hör normalt till finansieringsverksamheten (K3 punkt 7.4 kommentar) och bokförs på 2330 eller 2480', ['accounts' => self::list($negative)]);
            $this->addAccounts($negative);
        }

        $differs = array_keys(array_filter($this->accounts->openingDifferences(1900, 1999), fn (int $difference): bool => $difference !== 0));

        if ($differs !== []) {
            $this->reasons[] = (string) __('likvida medel vid årets början på konto :accounts skiljer sig från föregående års utgående balans', ['accounts' => self::list($differs)]);
            $this->addAccounts($differs);
        }
    }

    /**
     * The three activities must add up to the change in likvida medel, to the
     * öre. Checked when nothing else is wrong: an unclassified account, for
     * one, leaves the sums short by its movement and is the reason already.
     */
    private function checkReconciliation(): void
    {
        if ($this->reasons !== []) {
            return;
        }

        $change = $this->amounts['likvida_medel_vid_arets_slut'] - $this->amounts['likvida_medel_vid_arets_borjan'];

        if ($this->amounts['arets_kassaflode'] === $change) {
            return;
        }

        $this->reasons[] = (string) __('årets kassaflöde (:flow öre) är inte lika med förändringen av likvida medel (:change öre)', ['flow' => $this->amounts['arets_kassaflode'], 'change' => $change]);
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, int>
     */
    private static function withSubtotals(array $amounts): array
    {
        foreach (self::SUBTOTALS as $key => $lines) {
            $amounts[$key] = array_sum(array_map(fn (string $line): int => $amounts[$line] ?? 0, $lines));
        }

        return $amounts;
    }

    /**
     * @param  array<int, int>  $cash
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private static function cashOf(array $cash, array $ranges): int
    {
        $total = 0;

        foreach ($cash as $number => $amount) {
            if (CashFlowAccounts::inRanges($number, $ranges)) {
                $total += $amount;
            }
        }

        return $total;
    }

    /** @param  list<int>  $accounts */
    private function addAccounts(array $accounts): void
    {
        $all = array_values(array_unique([...$this->refusedAccounts, ...$accounts]));
        sort($all);
        $this->refusedAccounts = $all;
    }

    /** @param  list<int>  $accounts */
    private static function list(array $accounts): string
    {
        $accounts = array_values(array_unique($accounts));
        sort($accounts);

        return implode(', ', $accounts);
    }
}
