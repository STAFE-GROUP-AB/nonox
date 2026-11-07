<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Models\VoucherRow;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The cash flow statement of a K3 annual report, indirect method (ADR-059
 * A.4 S1-P8, addendum point 3; rule sheet S1-R2, S1-R2.1). Quotes from BFN's
 * consolidated K3 (vl12-1-k3-kons20251215, chapter 7, pp. 57–63, read
 * 2026-11-07).
 *
 * When: ÅRL 2 kap. 1 § andra stycket (Lag 2022:1028): "I årsredovisningen
 * för ett större företag eller en bostadsrättsförening ska det även ingå en
 * kassaflödesanalys." For any other company it is the company's choice, the
 * policy `cash_flow_statement` (K3Policies); K3 7.1: "Kapitlet ska även
 * tillämpas på en frivilligt upprättad kassaflödesanalys." forYear() applies
 * the policy, the size (K3CompanySize) and the legal form: `none` gives no
 * statement for a mindre aktiebolag and is refused for a större företag, for
 * a company whose size is undetermined and for an ekonomisk förening (NONOX
 * does not know whether it is a bostadsrättsförening); the direct method,
 * which K3 7.8 allows, is not built and is refused.
 *
 * How: K3 7.9: "Tillämpas indirekt metod, ska nettot av in- och utbetalningar
 * i den löpande verksamheten beräknas och redovisas genom att resultatet
 * justeras för a) icke-kassaflödespåverkande poster och alla övriga poster
 * vilkas kassaflödeseffekt hänförs till investeringsverksamheten eller
 * finansieringsverksamheten, samt b) periodens förändringar av varulager,
 * kundfordringar och leverantörsskulder samt förändringar av andra
 * rörelsefordringar och rörelseskulder." 7.6: "Investeringsverksamhet utgörs
 * av förvärv och avyttringar av anläggningstillgångar samt sådana
 * placeringar som inte omfattas av begreppet likvida medel." 7.7:
 * "Finansieringsverksamhet utgörs av åtgärder som medför förändringar i
 * storleken på och sammansättningen av företagets eget kapital och
 * upplåning." 7.11: "Ett företag ska redovisa väsentliga slag av in- och
 * utbetalningar hänförliga till investeringsverksamheten eller
 * finansieringsverksamheten brutto och separat i respektive kategori." 7.14:
 * "Kassaflöden från in- och utbetalningar som avser räntor och utdelningar
 * ska redovisas separat." 7.16: "Utbetald utdelning ska hänföras till
 * finansieringsverksamheten." 7.17: "Betalningar som avser inkomstskatter
 * ska redovisas separat och hänföras till den löpande verksamheten, …".
 * Kommentar "Transaktioner som inte medför betalningar": "Transaktioner som
 * hänförs till investerings- och finansieringsverksamheterna men som inte
 * medför in- och utbetalningar ska inte ingå i kassaflödet", for example
 * "förvärv av tillgångar genom att antingen överta en till tillgången direkt
 * relaterad skuld …" and "konvertering av skuld till eget kapital".
 *
 * Two reads:
 * - Balance changes per account (from AnnualAccounts, which has already read
 *   the ledger): each account's movement in the year belongs to one category
 *   of CashFlowAccounts, and the operating lines are the sums of their
 *   accounts' movements with the cash sign (inflow positive). Since every
 *   posted voucher balances, the activities add up to the change in likvida
 *   medel by construction; the statement checks it anyway.
 * - Voucher by voucher for investing and financing (one grouped query): each
 *   voucher that touches a fixed asset, an investment, a loan, an overdraft,
 *   equity or unpaid dividends is read with its counter accounts, a
 *   correcting voucher netted with the voucher it corrects (so a correction
 *   adds nothing gross). A voucher that moves such an account against a
 *   counter account that is no payment (a loan, a receivable, another
 *   equity post, unpaid dividends, an accrual) is a transaction without
 *   payment and blocks the statement with the voucher and its accounts: NONOX
 *   does not adjust it away. An acquisition on supplier credit (2440) counts
 *   as paid only when a payment of the same amount from 2440 to likvida medel
 *   follows in the year; otherwise it blocks. Each remaining voucher's net
 *   is an acquisition or a disposal, a borrowing or a repayment, so these are
 *   shown gross (7.11); a disposal of an intangible or tangible fixed asset
 *   blocks, as its proceeds cannot yet be split from the gain. A used
 *   overdraft is netted (7.12 b).
 *
 * Three account pairs must net to zero because neither side is cash:
 * depreciation against accumulated depreciation, deferred tax against its
 * provision or asset, and appropriations against untaxed reserves. The owner
 * transactions (dividends, contributions, new issues) come from
 * K3EquityStatement, the change of unpaid dividends (2898) turning a decided
 * dividend into a paid one; an equity statement that is refused refuses this
 * one too. The starting point is "Resultat efter finansiella poster" (F-S1-a
 * assumption A7).
 *
 * TODO(legal-verify): the line labels are NONOX's own (K3 gives no
 * wording); interest and dividends are shown in a separate section beside
 * the statement (7.14) with the payments estimated from the result accounts
 * and their accruals; matching a supplier-credit acquisition with a payment
 * of the same amount is NONOX's rule; a loan voucher with a result account
 * counter (interest, a fee withheld) is accepted only when likvida medel
 * cover the loan movement; notes 7.18–7.20 (större företag) are S1-P13. See
 * CashFlowAccounts for the account mapping.
 *
 * Queries per year: the equity statement and the voucher read; with the
 * previous year beside it, AnnualAccounts for that year and the same two.
 * The count does not depend on the number of vouchers.
 */
final class CashFlowStatement
{
    /**
     * The lines in the order they are shown: label, section, the K3 points
     * the line answers, whether it is a sum, and whether it is shown when zero.
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
        'kassaflode_fran_den_lopande_verksamheten' => ['label' => 'Kassaflöde från den löpande verksamheten', 'section' => 'operating', 'point' => '7.5, 7.8', 'subtotal' => true, 'always' => true],
        'forvarv_av_immateriella_anlaggningstillgangar' => ['label' => 'Förvärv av immateriella anläggningstillgångar', 'section' => 'investing', 'point' => '7.6, 7.11', 'subtotal' => false, 'always' => false],
        'forvarv_av_materiella_anlaggningstillgangar' => ['label' => 'Förvärv av materiella anläggningstillgångar', 'section' => 'investing', 'point' => '7.6, 7.11', 'subtotal' => false, 'always' => false],
        'forvarv_av_finansiella_anlaggningstillgangar' => ['label' => 'Förvärv av finansiella anläggningstillgångar', 'section' => 'investing', 'point' => '7.6, 7.11', 'subtotal' => false, 'always' => false],
        'avyttring_av_finansiella_anlaggningstillgangar' => ['label' => 'Avyttring och minskning av finansiella anläggningstillgångar', 'section' => 'investing', 'point' => '7.6, 7.11', 'subtotal' => false, 'always' => false],
        'forvarv_av_kortfristiga_placeringar' => ['label' => 'Förvärv av kortfristiga placeringar', 'section' => 'investing', 'point' => '7.6, 7.11', 'subtotal' => false, 'always' => false],
        'avyttring_av_kortfristiga_placeringar' => ['label' => 'Avyttring av kortfristiga placeringar', 'section' => 'investing', 'point' => '7.6, 7.11', 'subtotal' => false, 'always' => false],
        'kassaflode_fran_investeringsverksamheten' => ['label' => 'Kassaflöde från investeringsverksamheten', 'section' => 'investing', 'point' => '7.6', 'subtotal' => true, 'always' => true],
        'nyemission' => ['label' => 'Nyemission', 'section' => 'financing', 'point' => '7.7, 7.11', 'subtotal' => false, 'always' => false],
        'erhallna_aktieagartillskott' => ['label' => 'Erhållna aktieägartillskott', 'section' => 'financing', 'point' => '7.7, 7.11', 'subtotal' => false, 'always' => false],
        'aterbetalning_av_aktieagartillskott' => ['label' => 'Återbetalning av aktieägartillskott', 'section' => 'financing', 'point' => '7.7, 7.11', 'subtotal' => false, 'always' => false],
        'upptagna_lan' => ['label' => 'Upptagna lån', 'section' => 'financing', 'point' => '7.7, 7.11', 'subtotal' => false, 'always' => false],
        'amortering_av_lan' => ['label' => 'Amortering av lån', 'section' => 'financing', 'point' => '7.7, 7.11', 'subtotal' => false, 'always' => false],
        'forandring_av_checkrakningskredit' => ['label' => 'Förändring av checkräkningskredit', 'section' => 'financing', 'point' => '7.7, 7.12 b', 'subtotal' => false, 'always' => false],
        'utbetald_utdelning' => ['label' => 'Utbetald utdelning', 'section' => 'financing', 'point' => '7.16', 'subtotal' => false, 'always' => false],
        'kassaflode_fran_finansieringsverksamheten' => ['label' => 'Kassaflöde från finansieringsverksamheten', 'section' => 'financing', 'point' => '7.7', 'subtotal' => true, 'always' => true],
        'arets_kassaflode' => ['label' => 'Årets kassaflöde', 'section' => 'cash', 'point' => '7.2', 'subtotal' => true, 'always' => true],
        'likvida_medel_vid_arets_borjan' => ['label' => 'Likvida medel vid årets början', 'section' => 'cash', 'point' => '7.4', 'subtotal' => false, 'always' => true],
        'likvida_medel_vid_arets_slut' => ['label' => 'Likvida medel vid årets slut', 'section' => 'cash', 'point' => '7.4', 'subtotal' => false, 'always' => true],
        'tillaggsupplysning_betald_ranta' => ['label' => 'Betald ränta', 'section' => 'interest_dividends', 'point' => '7.14', 'subtotal' => false, 'always' => false],
        'tillaggsupplysning_erhallen_ranta' => ['label' => 'Erhållen ränta', 'section' => 'interest_dividends', 'point' => '7.14', 'subtotal' => false, 'always' => false],
        'tillaggsupplysning_erhallen_utdelning' => ['label' => 'Erhållen utdelning', 'section' => 'interest_dividends', 'point' => '7.14', 'subtotal' => false, 'always' => false],
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
        'kassaflode_fran_investeringsverksamheten' => ['forvarv_av_immateriella_anlaggningstillgangar', 'forvarv_av_materiella_anlaggningstillgangar', 'forvarv_av_finansiella_anlaggningstillgangar', 'avyttring_av_finansiella_anlaggningstillgangar', 'forvarv_av_kortfristiga_placeringar', 'avyttring_av_kortfristiga_placeringar'],
        'kassaflode_fran_finansieringsverksamheten' => ['nyemission', 'erhallna_aktieagartillskott', 'aterbetalning_av_aktieagartillskott', 'upptagna_lan', 'amortering_av_lan', 'forandring_av_checkrakningskredit', 'utbetald_utdelning'],
        'arets_kassaflode' => ['kassaflode_fran_den_lopande_verksamheten', 'kassaflode_fran_investeringsverksamheten', 'kassaflode_fran_finansieringsverksamheten'],
    ];

    /** BAS 2898 Outtagen vinstutdelning: a decided dividend not yet paid. */
    public const int UNPAID_DIVIDEND_ACCOUNT = 2898;

    /**
     * Per investing and financing category: the line an outflow and an inflow
     * of one voucher go to (null: an inflow blocks, see the class comment).
     *
     * @var array<string, array{0: string, 1: ?string}>
     */
    private const array GROSS_LINES = [
        CashFlowAccounts::INTANGIBLE => ['forvarv_av_immateriella_anlaggningstillgangar', null],
        CashFlowAccounts::TANGIBLE => ['forvarv_av_materiella_anlaggningstillgangar', null],
        CashFlowAccounts::FINANCIAL_ASSETS => ['forvarv_av_finansiella_anlaggningstillgangar', 'avyttring_av_finansiella_anlaggningstillgangar'],
        CashFlowAccounts::SHORT_INVESTMENTS => ['forvarv_av_kortfristiga_placeringar', 'avyttring_av_kortfristiga_placeringar'],
        CashFlowAccounts::LOANS => ['amortering_av_lan', 'upptagna_lan'],
    ];

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
     * The statement the report has for the year under its policy, size and
     * legal form, or null when it has none (a mindre aktiebolag with the
     * policy `none`). The legal form is read from the company when not given.
     *
     * @throws CashFlowStatementRefused when the policy is missing, is the direct method, or is `none` where ÅRL 2 kap. 1 § requires or may require a statement
     */
    public static function forYear(AnnualAccounts $accounts, K3Policies $policies, K3SizeClass $size, ?LegalForm $legalForm = null): ?self
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

        if ($value === K3CashFlowStatement::Indirect) {
            return self::of($accounts);
        }

        $legalForm ??= Company::query()->find($accounts->fiscalYear->company_id)?->legal_form;

        // NONOX does not record whether an ekonomisk förening is a bostadsrättsförening
        // (Bolagsverket's form code 53 is only read for prospecting), so none is refused.
        if ($legalForm === LegalForm::EkonomiskForening) {
            throw new CashFlowStatementRefused((string) __('En ekonomisk förening kan vara en bostadsrättsförening, och en bostadsrättsförening ska ha en kassaflödesanalys i årsredovisningen (årsredovisningslagen 2 kap. 1 § andra stycket). NONOX vet inte vilken sorts förening det är; välj indirekt metod.'));
        }

        return match ($size) {
            K3SizeClass::Mindre => null,
            K3SizeClass::Storre => throw new CashFlowStatementRefused((string) __('Ett större företag ska ha en kassaflödesanalys i årsredovisningen (årsredovisningslagen 2 kap. 1 §).')),
            K3SizeClass::Undetermined => throw new CashFlowStatementRefused((string) __('Företagets storlek går inte att avgöra, och ett större företag ska ha en kassaflödesanalys i årsredovisningen (årsredovisningslagen 2 kap. 1 §). Avgör storleken eller välj indirekt metod.')),
        };
    }

    /**
     * Every line by key, öre, inflow positive and outflow negative (interest
     * paid too); zero for a line with nothing.
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
        $gross = $this->grossFromVouchers();
        $owners = $this->ownerTransactions($cash[self::UNPAID_DIVIDEND_ACCOUNT] ?? 0, $of(CashFlowAccounts::EQUITY), $members[CashFlowAccounts::EQUITY] ?? []);

        $amounts = [
            'resultat_efter_finansiella_poster' => $this->accounts->resultAfterFinancialItems(),
            'justering_avskrivningar' => -$of(CashFlowAccounts::DEPRECIATION),
            'justering_avsattningar' => $of(CashFlowAccounts::PROVISIONS),
            'betald_inkomstskatt' => $of(CashFlowAccounts::INCOME_TAX),
            'forandring_varulager' => $of(CashFlowAccounts::INVENTORY),
            'forandring_rorelsefordringar' => $of(CashFlowAccounts::RECEIVABLES),
            'forandring_rorelseskulder' => $of(CashFlowAccounts::PAYABLES),
            ...$gross,
            ...$owners,
            'forandring_av_checkrakningskredit' => $of(CashFlowAccounts::OVERDRAFT),
            'likvida_medel_vid_arets_borjan' => $this->accounts->openingOf(1900, 1999),
            'likvida_medel_vid_arets_slut' => $this->accounts->closingOf(1900, 1999),
            'tillaggsupplysning_betald_ranta' => self::cashOf($cash, CashFlowAccounts::INTEREST_EXPENSE) + self::cashOf($cash, CashFlowAccounts::ACCRUED_INTEREST_EXPENSE),
            'tillaggsupplysning_erhallen_ranta' => self::cashOf($cash, CashFlowAccounts::INTEREST_INCOME) + self::cashOf($cash, CashFlowAccounts::ACCRUED_INTEREST_INCOME),
            'tillaggsupplysning_erhallen_utdelning' => self::cashOf($cash, CashFlowAccounts::DIVIDENDS_RECEIVED),
        ];

        $this->amounts = self::withSubtotals(array_merge(array_fill_keys(array_keys(self::LINES), 0), $amounts));

        $this->checkGross($sum, $members);
        $this->checkUnclassified($notBuilt);
        $this->checkPairs($sum, $members);
        $this->checkCash();
        $this->checkReconciliation();
    }

    /**
     * The gross investing and financing lines (K3 7.11), read voucher by
     * voucher, and the blocks for transactions without payment (K3 chapter 7
     * kommentar) and for disposals of intangible and tangible fixed assets.
     *
     * @return array<string, int>
     */
    private function grossFromVouchers(): array
    {
        $lines = array_fill_keys(self::SUBTOTALS['kassaflode_fran_investeringsverksamheten'], 0) + ['upptagna_lan' => 0, 'amortering_av_lan' => 0];

        $groups = self::withCorrections($this->vouchers());
        $payments = [];

        foreach ($groups as $group) {
            if ($group['payment']) {
                $paid = array_filter($group['rows'], fn (int $number): bool => self::inRange($number, CashFlowAccounts::TRADE_PAYABLES), ARRAY_FILTER_USE_KEY);
                $payments[] = ['date' => $group['date'], 'amount' => -array_sum($paid), 'used' => false];
            }
        }

        $paymentsBySupplierCredit = [];

        foreach ($groups as $group) {
            $families = [];
            $byCategory = [];
            $counters = [];

            foreach ($group['rows'] as $number => $amount) {
                if ($amount === 0) {
                    continue;
                }

                $category = CashFlowAccounts::categoryOf($number);

                if ($category !== null && in_array($category, CashFlowAccounts::INVESTING_FINANCING, true)) {
                    $families[self::family($category)] = true;
                    $byCategory[$category] = ($byCategory[$category] ?? 0) + $amount;

                    continue;
                }

                $counters[$number] = $amount;
            }

            if ($families === []) {
                continue;
            }

            $family = (string) array_key_first($families);
            $investing = array_sum($byCategory);
            // Two families in one voucher (a loan against a fixed asset, a loan turned into
            // equity) are an exchange without payment, whatever else the voucher holds.
            $mixed = count($families) > 1;
            $nonCash = [];
            $supplierCredit = 0;

            foreach ($counters as $number => $amount) {
                $category = CashFlowAccounts::categoryOf($number);

                if ($category === CashFlowAccounts::CASH || self::inRange($number, CashFlowAccounts::VAT)) {
                    continue;
                }

                if ($family === 'fixed' && in_array($category, [CashFlowAccounts::RESULT, CashFlowAccounts::DEPRECIATION, CashFlowAccounts::ACCUMULATED], true)) {
                    continue;
                }

                if (in_array($family, ['fixed', CashFlowAccounts::FINANCIAL_ASSETS, CashFlowAccounts::SHORT_INVESTMENTS], true) && self::inRange($number, CashFlowAccounts::TRADE_PAYABLES) && $amount > 0) {
                    $supplierCredit += $amount;

                    continue;
                }

                if ($category === CashFlowAccounts::RESULT && self::coveredByCash($counters, $investing)) {
                    continue;
                }

                $nonCash[] = $number;
            }

            if ($mixed || $nonCash !== []) {
                $accounts = array_merge(array_keys(array_filter($group['rows'], fn (int $amount, int $number): bool => $amount !== 0 && in_array(CashFlowAccounts::categoryOf($number), CashFlowAccounts::INVESTING_FINANCING, true), ARRAY_FILTER_USE_BOTH)), $nonCash);
                $this->reasons[] = (string) __('verifikation :vouchers bokför konto :accounts utan betalning; en transaktion i investerings- eller finansieringsverksamheten som inte medför in- eller utbetalningar ska inte ingå i kassaflödet (K3 kapitel 7, kommentaren om transaktioner som inte medför betalningar), och NONOX kan ännu inte visa den på annan plats', ['vouchers' => implode(', ', $group['references']), 'accounts' => self::list($accounts)]);
                $this->addAccounts($accounts);

                continue;
            }

            if ($supplierCredit !== 0) {
                $paymentsBySupplierCredit[] = ['group' => $group, 'amount' => $supplierCredit];
            }

            foreach ($byCategory as $category => $amount) {
                if (! isset(self::GROSS_LINES[$category])) {
                    continue;
                }

                [$outflow, $inflow] = self::GROSS_LINES[$category];

                if ($amount < 0) {
                    $lines[$outflow] += $amount;
                }

                if ($amount > 0 && $inflow !== null) {
                    $lines[$inflow] += $amount;
                }

                if ($amount > 0 && $inflow === null) {
                    $accounts = array_keys(array_filter($group['rows'], fn (int $value, int $number): bool => $value > 0 && CashFlowAccounts::categoryOf($number) === $category, ARRAY_FILTER_USE_BOTH));
                    $this->reasons[] = (string) __('verifikation :vouchers minskar anskaffningsvärdet på konto :accounts, och avyttring och utrangering av anläggningstillgångar kan ännu inte visas (K3 punkt 7.6)', ['vouchers' => implode(', ', $group['references']), 'accounts' => self::list($accounts)]);
                    $this->addAccounts($accounts);
                }
            }
        }

        $this->matchSupplierCredits($paymentsBySupplierCredit, $payments);

        return $lines;
    }

    /**
     * An acquisition booked against a trade payable is paid only when a
     * payment of the same amount from 2440–2449 to likvida medel follows in
     * the year; an acquisition still owed at the year end is no cash flow.
     *
     * @param  list<array{group: array{references: list<string>, date: string, rows: array<int, int>}, amount: int}>  $credits
     * @param  list<array{date: string, amount: int, used: bool}>  $payments
     */
    private function matchSupplierCredits(array $credits, array $payments): void
    {
        foreach ($credits as $credit) {
            foreach ($payments as $index => $payment) {
                if (! $payment['used'] && $payment['amount'] === $credit['amount'] && $payment['date'] >= $credit['group']['date']) {
                    $payments[$index]['used'] = true;

                    continue 2;
                }
            }

            $accounts = array_keys(array_filter($credit['group']['rows'], fn (int $amount, int $number): bool => $amount !== 0 && (self::inRange($number, CashFlowAccounts::TRADE_PAYABLES) || in_array(CashFlowAccounts::categoryOf($number), CashFlowAccounts::INVESTING_FINANCING, true)), ARRAY_FILTER_USE_BOTH));
            $this->reasons[] = (string) __('verifikation :vouchers bokför ett förvärv på konto :accounts mot en leverantörsskuld som inte betalas under året med samma belopp; ett förvärv mot en obetald skuld är ingen utbetalning (K3 kapitel 7, kommentaren om transaktioner som inte medför betalningar)', ['vouchers' => implode(', ', $credit['group']['references']), 'accounts' => self::list($accounts)]);
            $this->addAccounts($accounts);
        }
    }

    /**
     * The year's posted vouchers that touch an investing or financing account,
     * or that only pay a trade payable from likvida medel, with their rows
     * summed per account (credit positive, the cash sign). One query: the
     * year's rows grouped by voucher and account, with windows over each
     * voucher (no IN subquery, whose semi join was planned badly; S1-P7
     * review).
     *
     * @return list<array{id: string, references: list<string>, date: string, corrects: ?string, payment: bool, rows: array<int, int>}>
     */
    private function vouchers(): array
    {
        $touches = implode(' OR ', array_fill(0, count(CashFlowAccounts::VOUCHER_LEVEL), 'accounts.number BETWEEN ? AND ?'));
        [$payableFrom, $payableTo] = CashFlowAccounts::TRADE_PAYABLES;
        $bindings = [...array_merge(...CashFlowAccounts::VOUCHER_LEVEL), $payableFrom, $payableTo, 1900, 1999, $payableFrom, $payableTo];

        $rows = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->where('vouchers.fiscal_year_id', $this->accounts->fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->groupBy('voucher_rows.voucher_id', 'vouchers.voucher_date', 'vouchers.number', 'vouchers.corrects_voucher_id', 'voucher_series.code', 'accounts.number')
            ->selectRaw("voucher_rows.voucher_id, vouchers.voucher_date, vouchers.number AS voucher_number, vouchers.corrects_voucher_id, voucher_series.code AS series_code, accounts.number AS account_number, SUM(voucher_rows.credit_ore) - SUM(voucher_rows.debit_ore) AS net_credit, BOOL_OR({$touches}) OVER (PARTITION BY voucher_rows.voucher_id) AS touches_investing, BOOL_AND(accounts.number BETWEEN ? AND ? OR accounts.number BETWEEN ? AND ?) OVER (PARTITION BY voucher_rows.voucher_id) AS only_payable_and_cash, BOOL_OR(accounts.number BETWEEN ? AND ?) OVER (PARTITION BY voucher_rows.voucher_id) AS touches_payable", $bindings);

        $result = DB::query()
            ->fromSub($rows, 'year_rows')
            ->where(fn ($query) => $query->where('year_rows.touches_investing', true)->orWhere(fn ($query) => $query->where('year_rows.only_payable_and_cash', true)->where('year_rows.touches_payable', true)))
            ->orderBy('year_rows.voucher_date')
            ->orderBy('year_rows.series_code')
            ->orderBy('year_rows.voucher_number')
            ->orderBy('year_rows.account_number')
            ->get();

        $vouchers = [];

        foreach ($result as $row) {
            $id = (string) $row->voucher_id;
            $vouchers[$id] ??= [
                'id' => $id,
                'references' => [$row->series_code.$row->voucher_number],
                'date' => substr((string) $row->voucher_date, 0, 10),
                'corrects' => $row->corrects_voucher_id === null ? null : (string) $row->corrects_voucher_id,
                'payment' => (bool) $row->only_payable_and_cash && ! (bool) $row->touches_investing,
                'rows' => [],
            ];
            $vouchers[$id]['rows'][(int) $row->account_number] = (int) $row->net_credit;
        }

        return array_values($vouchers);
    }

    /**
     * A correcting voucher (CorrectVoucher writes the reversal and any
     * re-booked rows in one voucher) is netted with the voucher it corrects
     * when both are read, along the chain of corrections, so a corrected loan
     * counts once and a reversed one not at all (as K3EquityStatement does).
     *
     * @param  list<array{id: string, references: list<string>, date: string, corrects: ?string, payment: bool, rows: array<int, int>}>  $vouchers
     * @return list<array{references: list<string>, date: string, payment: bool, rows: array<int, int>}>
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
            $groups[$key] ??= ['references' => [], 'date' => $root['date'], 'payment' => $root['payment'], 'rows' => []];
            $groups[$key]['references'][] = $voucher['references'][0];
            $groups[$key]['payment'] = $groups[$key]['payment'] && $voucher['payment'];

            foreach ($voucher['rows'] as $number => $change) {
                $groups[$key]['rows'][$number] = ($groups[$key]['rows'][$number] ?? 0) + $change;
            }
        }

        return array_values($groups);
    }

    /**
     * The financing lines with the owners (K3 7.16 for dividends) from the
     * equity statement: a dividend decided in the year less the increase of
     * unpaid dividends on 2898 is the dividend paid. The equity accounts'
     * movement (without 2898) must equal the owner transactions, since the
     * year's result and the transfers between equity posts are no cash. An
     * owner transaction without payment (a contribution or new issue against
     * a loan or a receivable) is blocked voucher by voucher above.
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

    /**
     * The gross lines of a category must add up to its accounts' movement
     * in the year (every voucher on them is read); a difference would be a
     * voucher the read missed, and is refused rather than shown.
     *
     * @param  array<string, int>  $sum
     * @param  array<string, list<int>>  $members
     */
    private function checkGross(array $sum, array $members): void
    {
        if ($this->reasons !== []) {
            return;
        }

        foreach (self::GROSS_LINES as $category => [$outflow, $inflow]) {
            $gross = $this->amounts[$outflow] + ($inflow === null ? 0 : $this->amounts[$inflow]);

            if ($gross !== ($sum[$category] ?? 0)) {
                $this->reasons[] = (string) __('in- och utbetalningarna på konto :accounts går inte ihop med kontonas förändring under året', ['accounts' => self::list($members[$category] ?? [])]);
                $this->addAccounts($members[$category] ?? []);
            }
        }
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
            'currency' => 'konto :accounts är valutakursdifferenser; effekten av orealiserade valutakursförändringar i likvida medel ska visas skild från de tre verksamheterna (K3 punkt 7.13) och orealiserade kursdifferenser justeras bort (punkt 7.9 a), vilket NONOX ännu inte gör',
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

    /** Intangible and tangible fixed assets are one family: a transfer between them moves no cash. */
    private static function family(string $category): string
    {
        return in_array($category, [CashFlowAccounts::INTANGIBLE, CashFlowAccounts::TANGIBLE], true) ? 'fixed' : $category;
    }

    /**
     * Whether likvida medel in the voucher move at least the investing or
     * financing amount the other way, so that a result account beside them
     * (interest paid with an amortisation) is paid in the same voucher.
     *
     * @param  array<int, int>  $counters
     */
    private static function coveredByCash(array $counters, int $investing): bool
    {
        $cash = 0;

        foreach ($counters as $number => $amount) {
            if (CashFlowAccounts::categoryOf($number) === CashFlowAccounts::CASH) {
                $cash += $amount;
            }
        }

        return $cash !== 0 && ($cash > 0) !== ($investing > 0) && abs($cash) >= abs($investing);
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

    /** @param  array{0: int, 1: int}  $range */
    private static function inRange(int $number, array $range): bool
    {
        return $number >= $range[0] && $number <= $range[1];
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
