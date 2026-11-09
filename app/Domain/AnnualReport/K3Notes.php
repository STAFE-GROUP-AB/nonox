<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Actions\AnnualReport\SaveFrameworkChoice;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Assets\AssetCategory;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\AnnualReportNoteOverride;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use LogicException;

/**
 * The notes of a K3 annual report for a mindre aktiebolag, generated from data
 * (ADR-059, addendum 2026-11-07, A.2.7; package S1-P9). Each note answers a
 * row of the rule sheet's checklist S1-R8.1 (docs/specs/rules/s1.md, rows
 * 1–27) and is present only when its trigger holds. A person may replace the
 * wording of any note (AnnualReportNoteOverride); the data, the number and
 * whether the note is printed at all still come from the books.
 *
 * Order (K3 8.2, ÅRL 5 kap. 3 §): the principles (note 1, every principle
 * note shares the number), then the notes to items in the order of the
 * income statement and the balance sheet, then the other notes. The K3
 * document (S1-P10) prints them; this class only builds them.
 *
 * Mindre företag only. Notes that K3 or ÅRL ask of större företag alone are
 * never generated (LARGER_COMPANY_ONLY); a större or undetermined company is
 * refused (human decision S1-H1, refusal()).
 *
 * Queries: AnnualAccounts (when not given), the policies (when not given),
 * the overrides, the fixed asset register, the eligibility answers, the
 * corrections of earlier years' vouchers (and their series), the equity
 * statement, and for a first K3 year the transition proposal. None of them
 * depends on the number of vouchers, assets or notes.
 *
 * Enligt AI-utredning 2026-11-09, juristgranskning återstår. Every generated
 * wording is NONOX's own; K3 and ÅRL say what must be disclosed, not how.
 * TODO(legal-verify): the wording of every generated text, checked by the
 * accountant (H10) before K3 leaves beta.
 */
final class K3Notes
{
    public const string PRINCIPLES = 'principles';

    public const string ITEMS = 'items';

    public const string OTHER = 'other';

    /**
     * The policies note 1 lists as the company's accounting principles (K3
     * 8.3). The cash flow statement is a presentation choice and the
     * transition answers are facts about chapter 35, not principles.
     */
    public const array PRINCIPLE_POLICIES = ['financial_instruments', 'intangibles', 'leasing', 'borrowing_costs', 'fixed_price_contracts', 'components'];

    /**
     * Items NONOX merges in the statements (ÅRL 3 kap. 4 §; K3 3.5 comment:
     * "Mindre företag behöver endast lämna jämförelsetal i noter som avser
     * sammanslagna poster"). NONOX prints every line of K3Schedule, so none.
     */
    public const array MERGED_ITEMS = [];

    /**
     * Notes required of större företag only (rule sheet S1-R8.1, "Not required
     * of a mindre företag"). K3Notes never generates them; S1-P13 will.
     */
    public const array LARGER_COMPANY_ONLY = [
        'estimates_and_judgements', // K3 8.4, 8.5
        'cash_flow_disclosures', // K3 7.18–7.20
        'leases', // K3 20.12, 20.14
        'provisions_detail', // K3 21.16, 21.17
        'contracts_in_progress', // K3 23.30
        'deferred_tax', // ÅRL 5 kap. 36 §, K3 29.34–29.36 (S1-R4.7)
        'equity_reconciliation', // K3 35.33A
        'disposition', // ÅRL 5 kap. 35 §
        'staff_costs', // ÅRL 5 kap. 39–41 §§
        'auditor_fees', // ÅRL 5 kap. 48 §
    ];

    /** BAS ranges of exchange differences, as CashFlowStatement reads them (K3 8.3A). */
    public const array CURRENCY_RANGES = [[3960, 3969], [7960, 7969], [8230, 8239], [8330, 8339], [8430, 8439]];

    /** BAS 2085 Uppskrivningsfond (K3Schedule `revaluation_reserve`, ÅRL 5 kap. 11 §). */
    public const array REVALUATION_RESERVE = [2085, 2085];

    /** BAS 113x Mark: land, never depreciated (AssetCategory::Land, K3 17.13 comment). */
    public const array LAND_ACCOUNTS = [1130, 1139];

    /** The development fund (K3Schedule `development_fund`; account per human decision H9). */
    public const array DEVELOPMENT_FUND = [2089, 2089];

    /** Keys of the notes that are not per fixed asset line. */
    private const array KEYS = [
        'accounting_principles', 'fixed_assets_principles', 'income_tax_principles', 'revenue_principles',
        'intangibles_principles', 'foreign_currency_principles', 'departures', 'change_of_principle',
        'error_correction', 'first_time_application', 'comparability',
        'exceptional_items', 'goodwill', 'fair_value_instruments', 'equity_split', 'revaluation_reserve',
        'long_term_liabilities', 'merged_items',
        'average_employees', 'pledged_assets', 'contingent_liabilities', 'group_commitments',
        'off_balance_arrangements', 'loans_to_executives', 'related_parties', 'parent_company', 'events_after',
    ];

    /** @var list<array{key: string, row: int, section: string, heading: string, rules: list<string>, data: array<string, mixed>, text: list<string>, missing: list<string>, blocking: ?string}> */
    private array $drafts = [];

    /** @var list<array{key: string, row: int, message: string}> */
    private array $extraQuestions = [];

    /** @var list<K3Note> */
    private array $notes = [];

    /**
     * @param  list<string>  $mergedItems  the statement lines merged under ÅRL 3 kap. 4 § (MERGED_ITEMS)
     */
    private function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        public readonly AnnualReport $report,
        public readonly K3SizeClass $size,
        private readonly AnnualAccounts $accounts,
        private readonly K3Policies $policies,
        private readonly array $mergedItems,
    ) {}

    /**
     * @param  K3SizeClass  $size  the size test of the year (K3CompanySize), decided by the caller as CashFlowStatement::forYear() does
     * @param  ?AnnualAccounts  $accounts  the year's accounts in the K3 layout, when the caller already read them
     * @param  list<string>  $mergedItems  statement lines merged under ÅRL 3 kap. 4 §; NONOX merges none (MERGED_ITEMS)
     */
    public static function for(
        Company $company,
        FiscalYear $fiscalYear,
        AnnualReport $report,
        K3SizeClass $size,
        ?AnnualAccounts $accounts = null,
        ?K3Policies $policies = null,
        array $mergedItems = self::MERGED_ITEMS,
    ): self {
        return CurrentCompany::run($company, function () use ($company, $fiscalYear, $report, $size, $accounts, $policies, $mergedItems): self {
            $accounts ??= AnnualAccounts::for($fiscalYear, K3Schedule::class);

            if ($accounts->schedule !== K3Schedule::class) {
                throw new LogicException('The K3 notes need the K3 schedule.');
            }

            $notes = new self($company, $fiscalYear, $report, $size, $accounts, $policies ?? K3Policies::forYear($fiscalYear), $mergedItems);
            $notes->build();

            return $notes;
        });
    }

    /** Whether a key names a note a person may override: a fixed note or a fixed asset line. */
    public static function isKey(string $key): bool
    {
        if (in_array($key, self::KEYS, true)) {
            return true;
        }

        return str_starts_with($key, 'fixed_assets.') && in_array(substr($key, strlen('fixed_assets.')), K3Schedule::FIXED_ASSET_LINES, true);
    }

    /** @return list<K3Note> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function note(string $key): ?K3Note
    {
        foreach ($this->notes as $note) {
            if ($note->key === $key) {
                return $note;
            }
        }

        return null;
    }

    /**
     * What a person still has to answer before the notes are complete, in
     * Swedish: the questions of every note not written in the person's own
     * words, and questions about data no note can show yet.
     *
     * @return list<array{key: string, row: int, message: string}>
     */
    public function questions(): array
    {
        $questions = [];

        foreach ($this->notes as $note) {
            foreach ($note->openQuestions() as $message) {
                $questions[] = ['key' => $note->key, 'row' => $note->row, 'message' => $message];
            }
        }

        return [...$questions, ...$this->extraQuestions];
    }

    /** Why NONOX does not make these notes, in Swedish, or null. */
    public function refusal(): ?string
    {
        $refusal = match (true) {
            $this->fiscalYear->reporting_framework !== ReportingFramework::K3 => __('Noterna enligt K3 tas bara fram för ett räkenskapsår som följer K3. Räkenskapsåret :year följer :framework.', ['year' => $this->fiscalYear->label(), 'framework' => $this->fiscalYear->reporting_framework->value]),
            $this->company->legal_form !== LegalForm::Ab => __('NONOX tar fram noterna enligt K3 för aktiebolag.'),
            $this->size === K3SizeClass::Storre => __('NONOX tar ännu inte fram noterna för ett större företag (årsredovisningslagen 5 kap. 25–48 §§ och K3:s noter för större företag). Den delen kommer i ett senare steg (beslut S1-H1).'),
            $this->size === K3SizeClass::Undetermined => __('Det går inte att avgöra om företaget är ett mindre eller ett större företag, och noterna skiljer sig åt. Besvara frågorna om företagets storlek under Bokslut → Regelverk först.'),
            default => null,
        };

        return is_string($refusal) ? $refusal : null;
    }

    private function build(): void
    {
        $this->principles();
        $this->items();
        $this->other();
        $this->finish();
    }

    // ---- Note 1: principles (K3 8.2 first, 8.3) ----

    private function principles(): void
    {
        $policies = [];
        $missing = [];

        foreach (self::PRINCIPLE_POLICIES as $keyValue) {
            $key = K3PolicyKey::from($keyValue);
            $value = $this->policies->value($key);

            if ($value === null) {
                $missing[] = ['key' => $key->value, 'question' => $key->question()];

                continue;
            }

            $policies[] = ['key' => $key->value, 'value' => (string) $value->value, 'label' => $key->label(), 'value_label' => $key->valueLabel((string) $value->value), 'rule' => $key->rule()];
        }

        $text = [
            __('Årsredovisningen har upprättats i enlighet med årsredovisningslagen och Bokföringsnämndens allmänna råd BFNAR 2012:1 Årsredovisning och koncernredovisning (K3).'),
            // TODO(legal-verify): ÅRL 5 kap. 4 § asks for the valuation principles; this general sentence is NONOX's own.
            __('Tillgångar, avsättningar och skulder har värderats utifrån anskaffningsvärden om inget annat anges nedan.'),
        ];

        if ($policies !== []) {
            $text[] = __('Företaget har valt följande redovisningsprinciper: :list.', ['list' => implode('; ', array_map(fn (array $policy): string => "{$policy['label']}: {$policy['value_label']}", $policies))]);
        }

        $this->add('accounting_principles', 1, self::PRINCIPLES, __('Redovisningsprinciper'), ['ÅRL 5 kap. 4 §', 'K3 8.3'], [
            'framework' => 'BFNAR 2012:1',
            // BFN's version table (S1-R1.4); null for a year before the table, which the K3 document refuses anyway.
            'k3_version' => K3Rules::entry('k3_version_by_fiscal_year', $this->fiscalYear->start_date, $this->fiscalYear->end_date)['value'] ?? null,
            'policies' => $policies,
            'missing_policies' => $missing,
        ], $text, array_map(fn (array $policy): string => $policy['question'], $missing));

        $this->departures();
        $this->fixedAssetsPrinciples();
        $this->incomeTaxPrinciples();
        $this->revenuePrinciples();
        $this->intangiblesPrinciples();
        $this->foreignCurrencyPrinciples();
        $this->changeOfPrinciple();
        $this->errorCorrection();
        $this->transition();
    }

    /** Row 26: a departure from a general rule and its reasons (ÅRL 2 kap. 3 § andra stycket). */
    private function departures(): void
    {
        if (blank($this->report->k3_departures)) {
            return;
        }

        $this->add('departures', 26, self::PRINCIPLES, __('Avvikelser från allmänna råd'), ['ÅRL 2 kap. 3 §'], [], $this->paragraphs((string) $this->report->k3_departures));
    }

    /** S1-R3.7 and H4: depreciation method and the component principle (K3 17.4, 17.13, 17.23). */
    private function fixedAssetsPrinciples(): void
    {
        $components = $this->policies->value(K3PolicyKey::Components);

        if ($components === null && $this->accounts->fixedAssetNotes() === []) {
            return;
        }

        $text = [__('Materiella anläggningstillgångar redovisas till anskaffningsvärde med avdrag för ackumulerade avskrivningar. Avskrivning sker linjärt över tillgångens bedömda nyttjandeperiod.')];

        if ($components !== null) {
            $text[] = __('Byggnader delas upp i mark och betydande komponenter som skrivs av var för sig (K3 punkterna 17.4 och 17.13). Andra tillgångar delas upp när skillnaden i förbrukning mellan betydande komponenter bedöms vara väsentlig. Mark skrivs inte av.');
        }

        $this->add('fixed_assets_principles', 4, self::PRINCIPLES, __('Anläggningstillgångar'), ['ÅRL 5 kap. 8 §', 'K3 17.4', 'K3 17.13', 'K3 17.23'], [
            'method' => 'linear',
            'components' => $components?->value,
        ], $text);
    }

    /**
     * S1-R4.7: a mindre företag states its tax principle in note 1 (K3 8.3
     * comment: "skatter, inklusive uppskjuten skatt"); the deferred tax note of
     * ÅRL 5 kap. 36 § is a större-företag note.
     */
    private function incomeTaxPrinciples(): void
    {
        // TODO(legal-verify): wording; K3 29.19, 29.25 and 29.37 are quoted in the rule sheet (S1-R4, R4.3, R4.2).
        $this->add('income_tax_principles', 1, self::PRINCIPLES, __('Inkomstskatter'), ['K3 8.3', 'K3 29.19', 'K3 29.25', 'K3 29.37'], [], [
            __('Skatt på årets resultat består av aktuell skatt och uppskjuten skatt. Aktuell skatt är inkomstskatt för räkenskapsåret.'),
            __('Uppskjuten skatt redovisas på temporära skillnader mellan tillgångars och skulders redovisade och skattemässiga värden (K3 punkt 29.19) och beräknas med den skattesats som är beslutad före balansdagen (K3 punkt 29.25).'),
            __('Uppskjuten skatt som hänför sig till obeskattade reserver redovisas inte separat; obeskattade reserver redovisas med bruttobelopp i balansräkningen (K3 punkt 29.37).'),
        ]);
    }

    /**
     * Row 19: revenue recognition (K3 23.29, which applies to all companies).
     * TODO(legal-verify): 23.29 was not read in full (rule sheet S1-R8.1); the
     * wording is NONOX's own.
     */
    private function revenuePrinciples(): void
    {
        if ($this->accounts->movementOf(3000, 3799) === 0) {
            return;
        }

        $policy = $this->policies->value(K3PolicyKey::FixedPriceContracts);
        $text = [__('Intäkter redovisas enligt K3 kapitel 23.')];
        $missing = [];

        $text[] = match ($policy) {
            K3FixedPriceContracts::CompletedContract => __('Uppdrag till fast pris redovisas när uppdraget i allt väsentligt är fullgjort (färdigställandemetoden, K3 punkt 23.31).'),
            K3FixedPriceContracts::PercentageOfCompletion => __('Uppdrag till fast pris redovisas som intäkt i takt med att arbetet utförs (successiv vinstavräkning, K3 punkt 23.18).'),
            K3FixedPriceContracts::None => __('Företaget har inga uppdrag till fast pris.'),
            default => '',
        };

        if ($policy === K3FixedPriceContracts::PercentageOfCompletion) {
            $missing[] = __('Beskriv hur färdigställandegraden bestäms (K3 punkt 23.29). Skriv noten med egna ord när metoden är beskriven.');
        }

        if ($policy === null) {
            $missing[] = K3PolicyKey::FixedPriceContracts->question();
        }

        $this->add('revenue_principles', 19, self::PRINCIPLES, __('Intäktsredovisning'), ['K3 23.29'], [
            'fixed_price_contracts' => $policy?->value,
        ], array_values(array_filter($text)), $missing);
    }

    /** Row 27: the model for internally generated intangibles, when the books have any (K3 18.28 comment). */
    private function intangiblesPrinciples(): void
    {
        $policy = $this->policies->value(K3PolicyKey::Intangibles);
        $line = $this->balanceLine('development_expenditure');
        $onDevelopment = $this->rangesTouched($line['ranges']) || $this->rangesTouched([self::DEVELOPMENT_FUND]);

        if (! $onDevelopment && $policy !== K3Intangibles::Capitalise) {
            return;
        }

        $text = match ($policy) {
            K3Intangibles::Expense => [__('Utgifter för eget utvecklingsarbete redovisas som kostnad när de uppkommer (kostnadsföringsmodellen, K3 punkterna 18.7 och 18.8).')],
            K3Intangibles::Capitalise => [__('Utgifter för eget utvecklingsarbete redovisas som tillgång när villkoren i K3 punkt 18.12 är uppfyllda (aktiveringsmodellen).')],
            default => [],
        };

        $this->add('intangibles_principles', 27, self::PRINCIPLES, __('Internt upparbetade immateriella anläggningstillgångar'), ['K3 18.7', 'K3 18.28'], [
            'model' => $policy?->value,
        ], $text, $policy === null ? [K3PolicyKey::Intangibles->question()] : [], $policy?->refusal());
    }

    /** Row 2: translation of foreign currency, when the year has exchange differences (K3 8.3A). */
    private function foreignCurrencyPrinciples(): void
    {
        $accounts = [];

        foreach ($this->accounts->numbers() as $number) {
            if ($this->inRanges($number, self::CURRENCY_RANGES) && $this->accounts->movementOf($number, $number) !== 0) {
                $accounts[] = $number;
            }
        }

        if ($accounts === []) {
            return;
        }

        sort($accounts);

        // TODO(legal-verify): K3 8.3A and chapter 30 (not read for the rule sheet); the wording is NONOX's own.
        $this->add('foreign_currency_principles', 2, self::PRINCIPLES, __('Fordringar och skulder i utländsk valuta'), ['K3 8.3A'], [
            'accounts' => $accounts,
        ], [
            __('Fordringar och skulder i utländsk valuta har räknats om till balansdagens kurs. Kursdifferenser på rörelsefordringar och rörelseskulder redovisas i rörelseresultatet, och kursdifferenser på finansiella fordringar och skulder bland finansiella poster.'),
        ]);
    }

    /** Row 20: a change of principle in the year (K3 10.6, 10.7); the K3 document refuses it until chapter 10 is built. */
    private function changeOfPrinciple(): void
    {
        $changes = array_values(array_filter($this->policies->refusals(), fn (array $refusal): bool => in_array($refusal['rule'], ['K3 10.2', 'K3 23.37'], true)));

        if ($changes === []) {
            return;
        }

        $this->add('change_of_principle', 20, self::PRINCIPLES, __('Ändrade redovisningsprinciper'), ['K3 10.6', 'K3 10.7'], [
            'changes' => array_map(fn (array $change): array => ['key' => $change['key']->value, 'rule' => $change['rule']], $changes),
        ], array_map(fn (array $change): string => __('Redovisningsprincipen för :label har ändrats från föregående räkenskapsår.', ['label' => mb_strtolower($change['key']->label())]), $changes), [], $changes[0]['message']);
    }

    /**
     * Row 21: an error of an earlier year corrected in this year without
     * restating the comparatives (K3 10.14). TODO(legal-verify): 10.14 was not
     * read beyond the rule sheet's row; the wording is NONOX's own.
     */
    private function errorCorrection(): void
    {
        $corrections = Voucher::query()
            ->where('fiscal_year_id', $this->fiscalYear->id)
            ->where('status', VoucherStatus::Posted->value)
            ->whereIn('corrects_voucher_id', Voucher::query()->select('id')->where('fiscal_year_id', '!=', $this->fiscalYear->id))
            ->with('series:id,code')
            ->orderBy('number')
            ->get(['id', 'series_id', 'number', 'voucher_date']);

        if ($corrections->isEmpty()) {
            return;
        }

        $references = $corrections->map(fn (Voucher $voucher): string => $voucher->reference())->values()->all();

        $this->add('error_correction', 21, self::PRINCIPLES, __('Rättelse av fel'), ['K3 10.14'], [
            'vouchers' => $corrections->map(fn (Voucher $voucher): array => ['id' => $voucher->id, 'reference' => $voucher->reference()])->values()->all(),
        ], [
            __('Under räkenskapsåret har fel från tidigare räkenskapsår rättats (verifikation :list). Jämförelsetalen har inte räknats om, och jämförbarheten med föregående räkenskapsår är därför begränsad (K3 punkt 10.14).', ['list' => implode(', ', $references)]),
        ]);
    }

    /**
     * Rows 22 and 23: the first application of K3 (K3 35.31, 35.33) and the
     * lacking comparability when the comparatives are not restated (ÅRL 3 kap.
     * 5 § fjärde stycket, K3 35.7), from S1-P12's transition. The texts are
     * K3TransitionProposal::comparabilityNote(), split in two: its second
     * paragraph is the comparability, the rest the first application.
     */
    private function transition(): void
    {
        $previous = $this->accounts->previousYear;
        $restated = $this->policies->value(K3PolicyKey::ComparativesRestatedAtTransition);

        if (! SaveFrameworkChoice::isFirstK3Year($this->fiscalYear, $previous)) {
            return;
        }

        // Without the year before in NONOX, only the transition answers say that the company left K2.
        if ($previous === null && $restated === null) {
            return;
        }

        $proposal = K3TransitionProposal::for($this->company, $this->fiscalYear);
        $disclosures = $proposal->disclosures();
        $paragraphs = $proposal->comparabilityNote();
        $comparability = $paragraphs[1] ?? null;
        unset($paragraphs[1]);

        $this->add('first_time_application', 22, self::PRINCIPLES, __('Första tillämpningen av K3'), ['K3 35.31', 'K3 35.33'], [
            'transition_date' => $disclosures['transition_date'],
            'changed_principles' => $disclosures['changed_principles'],
            'reliefs' => $disclosures['reliefs'],
            'relief_items' => $disclosures['relief_items'],
            'equity_effect_ore' => $disclosures['equity_effect_ore'],
            'booked' => $proposal->isBooked(),
        ], array_values($paragraphs), $proposal->isBooked() ? [] : [__('Övergången till K3 är inte bokförd. Bokför den under Bokslut → Övergång till K3.')]);

        if ($restated === K3YesNo::No && $comparability !== null) {
            $this->add('comparability', 23, self::PRINCIPLES, __('Jämförbarhet'), ['ÅRL 3 kap. 5 §', 'K3 35.7'], [
                'previous_label' => $disclosures['previous_label'],
                'comparatives_restated' => false,
            ], [$comparability]);
        }
    }

    // ---- Notes to items, income statement then balance sheet (ÅRL 5 kap. 3 §) ----

    private function items(): void
    {
        $this->exceptionalItems();

        foreach ($this->fixedAssetNotes() as $note) {
            $this->add(...$note);

            if ($note['key'] === 'fixed_assets.goodwill') {
                $this->goodwill();
            }
        }

        if ($this->balanceLineAmount('goodwill') !== 0 && $this->drafted('goodwill') === false) {
            $this->goodwill();
        }

        $this->fairValueInstruments();
        $this->equitySplit();
        $this->revaluationReserve();
        $this->longTermLiabilities();
        $this->mergedItems();
    }

    /** Row 14: the nature and amount of each exceptional income or expense (ÅRL 5 kap. 19 §). */
    private function exceptionalItems(): void
    {
        $items = array_map(fn (array $item): array => ['description' => (string) $item['description'], 'amount_ore' => (int) $item['amount_ore']], $this->report->k3_exceptional_items ?? []);

        if ($items === []) {
            return;
        }

        $this->add('exceptional_items', 14, self::ITEMS, __('Poster av exceptionell storlek eller förekomst'), ['ÅRL 5 kap. 19 §'], [
            'items' => $items,
        ], array_map(fn (array $item): string => __(':description: :amount.', ['description' => $item['description'], 'amount' => Money::ofOre($item['amount_ore'])->format()]), $items));
    }

    /**
     * Row 4 and S1-R3.7: per fixed asset line of the balance sheet, the ÅRL 5
     * kap. 8 § movements and K3 17.23's "avskrivningsmetod och tillämpad
     * nyttjandeperiod". The lives come from the register (a component's total
     * life); land has none. A line with depreciable assets and nothing in the
     * register asks for its life. Accumulated figures are read as AnnualAccounts
     * reads them (ÅRL 5 kap. 25 § makes them required only of större företag;
     * printing them is allowed).
     *
     * @return list<array{key: string, row: int, section: string, heading: string, rules: list<string>, data: array<string, mixed>, text: list<string>, missing: list<string>, blocking: ?string}>
     */
    private function fixedAssetNotes(): array
    {
        $assets = FixedAsset::query()
            ->where('status', '!=', FixedAsset::SPLIT)
            ->get(['id', 'category', 'asset_account', 'useful_life_months', 'total_useful_life_months', 'status']);
        $drafts = [];

        foreach ($this->accounts->fixedAssetNotes() as $note) {
            $line = $this->balanceLine($note['key']);
            $inLine = $assets->filter(fn (FixedAsset $asset): bool => $this->inRanges((int) $asset->asset_account, $line['ranges']));
            $lives = [];

            foreach ($inLine->filter(fn (FixedAsset $asset): bool => $asset->category->depreciates())->groupBy(fn (FixedAsset $asset): string => $asset->category->value) as $category => $group) {
                $years = $group->map(fn (FixedAsset $asset): float => $asset->appliedLifeMonths() / 12)->values()->all();

                if ($years === []) {
                    continue;
                }

                $span = min($years) === max($years) ? self::years(min($years)) : self::years(min($years)).'–'.self::years(max($years));
                $lives[] = ['category' => (string) $category, 'label' => AssetCategory::from((string) $category)->label(), 'years' => $span];
            }

            $landOnLedger = $this->inRanges(self::LAND_ACCOUNTS[0], $line['ranges']) && $this->accounts->closingOf(...self::LAND_ACCOUNTS) !== 0;
            $land = $landOnLedger || $inLine->contains(fn (FixedAsset $asset): bool => $asset->category === AssetCategory::Land);
            $landCost = $this->inRanges(self::LAND_ACCOUNTS[0], $line['ranges']) ? $this->accounts->closingOf(...self::LAND_ACCOUNTS) : 0;
            $depreciable = $note['acquisition']['closing'] - $landCost !== 0 || $note['depreciation']['charge'] !== 0;

            $text = [];

            if ($lives !== []) {
                $text[] = __('Avskrivning sker linjärt över nyttjandeperioden: :lives.', ['lives' => implode(', ', array_map(fn (array $life): string => __(':kind :years år', ['kind' => mb_strtolower($life['label']), 'years' => $life['years']]), $lives))]);
            }

            if ($land) {
                $text[] = __('Mark skrivs inte av.');
            }

            $missing = $lives === [] && $depreciable && $note['key'] !== 'goodwill'
                ? [__('Ange avskrivningsmetod och nyttjandeperiod för :label (K3 punkt 17.23): lägg upp tillgångarna i anläggningsregistret, eller skriv noten med egna ord.', ['label' => mb_strtolower($note['label'])])]
                : [];

            $drafts[] = [
                'key' => "fixed_assets.{$note['key']}",
                'row' => 4,
                'section' => self::ITEMS,
                'heading' => $note['label'],
                'rules' => ['ÅRL 5 kap. 8 §', 'K3 17.23'],
                'data' => [
                    'line' => $note['key'],
                    'acquisition' => $note['acquisition'],
                    'depreciation' => $note['depreciation'],
                    'book_value' => $note['book_value'],
                    'method' => 'linear',
                    'lives' => $lives,
                    'land' => $land,
                ],
                'text' => $text,
                'missing' => $missing,
                'blocking' => null,
            ];
        }

        return $drafts;
    }

    /**
     * Row 5: goodwill (ÅRL 5 kap. 9 §). The period and its reasons are the
     * person's text. TODO(legal-verify): the exact content of 5 kap. 9 § was
     * taken from the rule sheet's row, not re-read.
     */
    private function goodwill(): void
    {
        $text = $this->report->k3_goodwill_note;

        $this->add('goodwill', 5, self::ITEMS, __('Goodwill'), ['ÅRL 5 kap. 9 §'], [
            'carrying_ore' => $this->balanceLineAmount('goodwill'),
        ], blank($text) ? [] : $this->paragraphs((string) $text), blank($text) ? [__('Ange över hur lång tid goodwill skrivs av och skälen för det (årsredovisningslagen 5 kap. 9 §).')] : []);
    }

    /** Row 6: instruments at fair value (ÅRL 5 kap. 10 §); chapter 12 is refused in S1 (S1-R6). */
    private function fairValueInstruments(): void
    {
        $policy = $this->policies->value(K3PolicyKey::FinancialInstruments);

        if ($policy !== K3FinancialInstruments::Chapter12) {
            return;
        }

        $this->add('fair_value_instruments', 6, self::ITEMS, __('Finansiella instrument värderade till verkligt värde'), ['ÅRL 5 kap. 10 §', 'S1-R6'], [
            'policy' => $policy->value,
        ], [], [], $policy->refusal());
    }

    /**
     * Row 25: an aktiebolag's equity split into bound and free (ÅRL 3 kap. 10 a
     * §), with the columns of the K3 6.3 statement (K3EquityStatement::columns(),
     * the follow-up recorded at S1-P7). A statement that is refused blocks.
     */
    private function equitySplit(): void
    {
        if ($this->company->legal_form !== LegalForm::Ab) {
            return;
        }

        $statement = K3EquityStatement::of($this->accounts);
        $refusal = $statement->refusal();
        $closing = [];

        if ($refusal === null) {
            foreach ($statement->rows() as $row) {
                if ($row['key'] === 'closing') {
                    $closing = $row['amounts'];
                }
            }
        }

        $bound = [];
        $free = [];

        foreach ($statement->columns() as $column) {
            $amount = $closing[$column] ?? 0;

            if ($amount === 0) {
                continue;
            }

            $entry = ['key' => $column, 'label' => K3EquityStatement::columnLabel($column), 'amount_ore' => $amount];

            if ($this->equitySection($column) === 'restricted') {
                $bound[] = $entry;
            } else {
                $free[] = $entry;
            }
        }

        $boundTotal = array_sum(array_column($bound, 'amount_ore'));
        $freeTotal = array_sum(array_column($free, 'amount_ore'));

        $this->add('equity_split', 25, self::ITEMS, __('Bundet och fritt eget kapital'), ['ÅRL 3 kap. 10 a §'], [
            'bound' => $bound,
            'free' => $free,
            'bound_total_ore' => $boundTotal,
            'free_total_ore' => $freeTotal,
        ], $refusal !== null ? [] : [
            __('Bundet eget kapital uppgår till :bound och fritt eget kapital till :free.', ['bound' => Money::ofOre($boundTotal)->format(), 'free' => Money::ofOre($freeTotal)->format()]),
        ], [], $refusal);
    }

    /** Row 7: the revaluation reserve, when it changed in the year (ÅRL 5 kap. 11 §). */
    private function revaluationReserve(): void
    {
        $change = -$this->accounts->movementOf(...self::REVALUATION_RESERVE);

        if ($change === 0) {
            return;
        }

        $opening = -$this->accounts->openingOf(...self::REVALUATION_RESERVE);

        $this->add('revaluation_reserve', 7, self::ITEMS, __('Uppskrivningsfond'), ['ÅRL 5 kap. 11 §'], [
            'opening_ore' => $opening,
            'change_ore' => $change,
            'closing_ore' => $opening + $change,
        ], [
            __('Uppskrivningsfonden uppgick vid årets början till :opening, har under året förändrats med :change och uppgår vid årets slut till :closing.', ['opening' => Money::ofOre($opening)->format(), 'change' => Money::ofOre($change)->format(), 'closing' => Money::ofOre($opening + $change)->format()]),
        ]);
    }

    /**
     * Row 8: liabilities due later than five years after the balance date
     * (ÅRL 5 kap. 13 §). The ledger does not know maturities: a person enters
     * the sum, and is asked while long-term debt has no answer.
     */
    private function longTermLiabilities(): void
    {
        $entered = $this->report->k3_long_term_liabilities_ore;
        $longTerm = 0;

        foreach (K3Schedule::BALANCE as $line) {
            if ($line['section'] === 'long_term') {
                foreach ($line['ranges'] as [$from, $to]) {
                    $longTerm -= $this->accounts->closingOf($from, $to);
                }
            }
        }

        if ($entered === null && $longTerm !== 0) {
            $this->extraQuestions[] = ['key' => 'long_term_liabilities', 'row' => 8, 'message' => __('Hur stor del av de långfristiga skulderna förfaller till betalning senare än fem år efter balansdagen (årsredovisningslagen 5 kap. 13 §)? Ange 0 om ingen del gör det.')];
        }

        if ($entered === null || $entered === 0) {
            return;
        }

        $this->add('long_term_liabilities', 8, self::ITEMS, __('Långfristiga skulder'), ['ÅRL 5 kap. 13 §'], [
            'due_after_five_years_ore' => $entered,
            'long_term_ore' => $longTerm,
        ], [
            __('Skulder som förfaller till betalning senare än fem år efter balansdagen uppgår till :amount.', ['amount' => Money::ofOre($entered)->format()]),
        ]);
    }

    /** Row 24: comparatives in the notes of merged items (K3 3.5 comment); NONOX merges none. */
    private function mergedItems(): void
    {
        if ($this->mergedItems === []) {
            return;
        }

        $this->add('merged_items', 24, self::ITEMS, __('Sammanslagna poster'), ['ÅRL 3 kap. 4 §', 'K3 3.5'], [
            'items' => $this->mergedItems,
        ], [__('Poster som har slagits samman i resultat- eller balansräkningen specificeras här med jämförelsetal.')]);
    }

    // ---- Other notes ----

    private function other(): void
    {
        $this->averageEmployees();
        $this->pledgedAssets();
        $this->contingentLiabilities();
        $this->textNote('group_commitments', 11, __('Åtaganden till förmån för koncern- och intresseföretag'), ['ÅRL 5 kap. 16 §'], $this->report->group_commitments);
        // ÅRL 5 kap. 17 §: "Första stycket 2 gäller inte mindre företag", so no economic effect is asked.
        $this->textNote('off_balance_arrangements', 12, __('Arrangemang som inte tas upp i balansräkningen'), ['ÅRL 5 kap. 17 §'], $this->report->k3_off_balance_arrangements);
        $this->textNote('loans_to_executives', 13, __('Lån till ledande befattningshavare'), ['ÅRL 5 kap. 18 §'], $this->report->k3_loans_to_executives);
        $this->relatedParties();
        $this->textNote('parent_company', 16, __('Moderföretag'), ['ÅRL 5 kap. 21 §'], $this->report->k3_parent_company);
        $this->eventsAfter();
    }

    /**
     * Row 15: the average number of employees, computed per BFNAR 2006:11 (K3
     * 8.6B): the full-time equivalent rounded half up to a whole person when
     * given, as the size test does (ADR-059 A.2.5), else the whole number.
     * TODO(legal-verify): rounding the printed figure to whole persons.
     */
    private function averageEmployees(): void
    {
        $fte = $this->report->average_employees_fte;
        $average = $fte !== null ? (int) round((float) $fte, 0, PHP_ROUND_HALF_UP) : (int) ($this->report->average_employees ?? 0);

        $this->add('average_employees', 15, self::OTHER, __('Medelantalet anställda'), ['ÅRL 5 kap. 20 §', 'K3 8.6B'], [
            'average_employees' => $average,
        ], [__('Medelantalet anställda under räkenskapsåret har varit :count.', ['count' => $average])]);
    }

    /** Row 9: security pledged (ÅRL 5 kap. 14 §, K3 8.6A). */
    private function pledgedAssets(): void
    {
        $rows = AnnualReportDocument::pledged($this->report);

        if ($rows === []) {
            return;
        }

        $total = array_sum(array_column($rows, 'amount'));

        // TODO(legal-verify): the sentence on how K3 8.6A values each kind of security.
        $this->add('pledged_assets', 9, self::OTHER, __('Ställda säkerheter'), ['ÅRL 5 kap. 14 §', 'K3 8.6A'], [
            'rows' => $rows,
            'total_ore' => $total,
        ], [
            __('Ställda säkerheter uppgår till :amount: :list.', ['amount' => Money::ofOre($total)->format(), 'list' => implode(', ', array_map(fn (array $row): string => mb_strtolower($row['label']).' '.Money::ofOre($row['amount'])->format(), $rows))]),
            __('Panter och liknande säkerheter anges till redovisat värde och inteckningar till nominellt belopp (K3 punkt 8.6A).'),
        ]);
    }

    /** Row 10: contingent liabilities, as an estimate of their financial effect (ÅRL 5 kap. 15 §, K3 21.16A). */
    private function contingentLiabilities(): void
    {
        $amount = $this->report->contingent_liabilities_ore;
        $text = $this->report->contingent_liabilities_text;

        if ($amount === null && blank($text)) {
            return;
        }

        $label = filled($text) ? (string) $text : __('Eventualförpliktelser');

        $this->add('contingent_liabilities', 10, self::OTHER, __('Eventualförpliktelser'), ['ÅRL 5 kap. 15 §', 'K3 21.16A'], [
            'amount_ore' => $amount,
            'description' => $text,
        ], [$amount === null ? $label.'.' : __(':label: :amount.', ['label' => $label, 'amount' => Money::ofOre($amount)->format()])], $amount === null
            ? [__('Ange en uppskattning av eventualförpliktelsernas finansiella effekt, eller skriv noten med egna ord och ange varför den inte kan uppskattas (K3 punkt 21.16A).')]
            : []);
    }

    /** Row 18: related-party transactions, for a public aktiebolag only (ÅRL 5 kap. 23 § tredje stycket). */
    private function relatedParties(): void
    {
        if (K3EligibilityAnswers::forYear($this->fiscalYear)->answer(K3EligibilityQuestion::PublicCompany) !== true) {
            return;
        }

        $text = $this->report->k3_related_party_transactions;

        $this->add('related_parties', 18, self::OTHER, __('Transaktioner med närstående'), ['ÅRL 5 kap. 23 §'], [], blank($text) ? [] : $this->paragraphs((string) $text), blank($text)
            ? [__('Ett publikt aktiebolag ska lämna upplysningar om transaktioner med närstående (årsredovisningslagen 5 kap. 23 §). Beskriv dem.')]
            : []);
    }

    /** Row 17: significant events after the year (ÅRL 5 kap. 22 §, K3 32.4). */
    private function eventsAfter(): void
    {
        if (blank($this->report->events_after)) {
            return;
        }

        $text = $this->paragraphs((string) $this->report->events_after);

        if ($this->report->events_effect_unknown) {
            $text[] = __('Den ekonomiska effekten av händelserna kan inte uppskattas.');
        }

        $this->add('events_after', 17, self::OTHER, __('Väsentliga händelser efter räkenskapsårets slut'), ['ÅRL 5 kap. 22 §', 'K3 32.4'], [
            'effect_unknown' => $this->report->events_effect_unknown,
        ], $text);
    }

    /**
     * A note made only of a person's text: present when the text is.
     *
     * @param  list<string>  $rules
     */
    private function textNote(string $key, int $row, string $heading, array $rules, ?string $text): void
    {
        if (blank($text)) {
            return;
        }

        $this->add($key, $row, self::OTHER, $heading, $rules, [], $this->paragraphs((string) $text));
    }

    // ---- Assembly ----

    /**
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $data
     * @param  list<string>  $text
     * @param  list<string>  $missing
     */
    private function add(string $key, int $row, string $section, string $heading, array $rules, array $data, array $text, array $missing = [], ?string $blocking = null): void
    {
        $this->drafts[] = compact('key', 'row', 'section', 'heading', 'rules', 'data', 'text', 'missing', 'blocking');
    }

    private function drafted(string $key): bool
    {
        return in_array($key, array_column($this->drafts, 'key'), true);
    }

    /** Numbers the notes (every principle is note 1) and lays the overrides over the text. */
    private function finish(): void
    {
        $overrides = $this->report->exists
            ? AnnualReportNoteOverride::query()->where('annual_report_id', $this->report->id)->get()->keyBy('note_key')
            : collect();
        $order = [self::PRINCIPLES => 0, self::ITEMS => 1, self::OTHER => 2];
        $drafts = $this->drafts;
        // A stable sort by section keeps the order within each section.
        uksort($drafts, fn (int $a, int $b): int => [$order[$drafts[$a]['section']], $a] <=> [$order[$drafts[$b]['section']], $b]);
        $number = 1;

        foreach ($drafts as $draft) {
            if ($draft['section'] !== self::PRINCIPLES) {
                $number++;
            }

            /** @var ?AnnualReportNoteOverride $override */
            $override = $overrides->get($draft['key']);

            $this->notes[] = new K3Note(
                key: $draft['key'],
                row: $draft['row'],
                section: $draft['section'],
                heading: $draft['heading'],
                rules: $draft['rules'],
                data: $draft['data'],
                generatedText: $draft['text'],
                number: $draft['section'] === self::PRINCIPLES ? 1 : $number,
                overrideText: $override?->text,
                overrideReason: $override?->reason,
                stale: $override !== null && $override->generated_hash !== K3Note::hash($draft['text']),
                missing: $draft['missing'],
                blocking: $draft['blocking'],
            );
        }
    }

    // ---- Helpers ----

    /** @return array{key: string, label: string, section: string, ranges: list<array{0: int, 1: int}>, source: string} */
    private function balanceLine(string $key): array
    {
        foreach (K3Schedule::BALANCE as $line) {
            if ($line['key'] === $key) {
                return $line;
            }
        }

        throw new LogicException("No K3 balance sheet line {$key}.");
    }

    /** The closing balance of a balance sheet line, debit positive. */
    private function balanceLineAmount(string $key): int
    {
        $total = 0;

        foreach ($this->balanceLine($key)['ranges'] as [$from, $to]) {
            $total += $this->accounts->closingOf($from, $to);
        }

        return $total;
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function rangesTouched(array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($this->accounts->closingOf($from, $to) !== 0 || $this->accounts->movementOf($from, $to) !== 0) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0: int, 1: int}>  $ranges */
    private function inRanges(int $number, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($number >= $from && $number <= $to) {
                return true;
            }
        }

        return false;
    }

    /** The section of an equity column: `restricted` or `free` (the year's result is free). */
    private function equitySection(string $column): string
    {
        foreach (K3Schedule::BALANCE as $line) {
            if ($line['key'] === $column) {
                return $line['section'];
            }
        }

        return 'free';
    }

    /** @return list<string> */
    private function paragraphs(string $text): array
    {
        $paragraphs = preg_split('/\R\s*\R/u', trim($text)) ?: [];

        return array_values(array_filter(array_map('trim', $paragraphs), fn (string $paragraph): bool => $paragraph !== ''));
    }

    private static function years(float $years): string
    {
        return rtrim(rtrim(number_format($years, 1, ',', ''), '0'), ',');
    }
}
