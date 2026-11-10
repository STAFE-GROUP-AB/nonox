<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Actions\AnnualReport\SaveFrameworkChoice;
use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\AnnualReport;
use App\Models\ClosingCheck;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\App;
use LogicException;

/**
 * The K3 annual report of a mindre aktiebolag (ADR-059, A.4 S1-P10), Beta.
 * Put together from what S1-P1–P9 and P12 build: the förvaltningsberättelse
 * (K3ManagementReport), the income statement and the balance sheet in the
 * K3 layout (K3Schedule through AnnualAccounts and PrintedFigures), the
 * specification of changes in equity as its own report (K3EquityStatement),
 * a cash flow statement when the policy asks for one (CashFlowStatement),
 * the notes (K3Notes, with a person's own wording marked) and the
 * signatures. The parts follow K3 3.10 (S1-R8.2, K3Schedule::PARTS).
 *
 * K3 is all or nothing (K3 1.4, 1.6; ADR-059 §1.0): when anything the
 * report would need is outside what NONOX supports, the whole document is
 * refused with the rule behind it (refusals()), never printed in part. The
 * reasons: a legal form other than aktiebolag (an ekonomisk förening on K3
 * is outside S1); a större företag until human decision S1-H1 (S1-R9.3) and
 * a size that cannot be determined (S1-R9); no K3 version for the year
 * (S1-R1.4); a policy missing or one NONOX does not support (chapter 12,
 * S1-R6; the capitalisation model, S1-R10; leases classified, S1-R5;
 * capitalised borrowing costs; a change of principle, K3 10.2); the
 * expensing model with capitalised development or a development fund in the
 * ledger (S1-R10.3, H9); a first K3 year whose transition is not booked or
 * whose comparatives would be restated (S1-R7.1, R7.7); an equity
 * specification or cash flow statement that cannot be drawn up (S1-R8.3,
 * S1-R2); a balance sheet that does not balance; a confirmed set-off of
 * deferred tax (K3 29.32), which the K3 balance sheet does not show netted
 * yet; and any note that describes something refused.
 *
 * Questions are not refusals: the preview is shown with them listed, as a
 * draft, and the PDF is made only once every question is answered (complete()).
 *
 * Queries: a fixed set, independent of the number of vouchers, assets and
 * notes (ADR-059 A.5): the year's neighbours, the eligibility answers, the
 * size test, the policies, AnnualAccounts in the K3 layout (with the
 * previous year), the equity statement, the cash flow statement when one is
 * made, K3Notes' and K3ManagementReport's own, and the set-off check.
 *
 * Enligt AI-utredning 2026-11-10, juristgranskning återstår. TODO(legal-verify):
 * the document as a whole, by the human accountant who signs the checklist
 * (H10), before the claim "Årsredovisning enligt K3" may be made.
 */
final class K3AnnualReportDocument
{
    /** The rule id of the legal-form refusal: only an aktiebolag is in S1 (ADR-059 A.4, "Not in S1: ekonomisk förening on K3"). */
    public const string RULE_LEGAL_FORM = 'S1-scope';

    /** The only K3 version read for S1 (k3-rules.json `k3_version_by_fiscal_year`, S1-R1.4). */
    public const string K3_VERSION = 'kons20251215';

    /**
     * BAS accounts that show capitalised own development (101x, 1081) and the
     * fund for development expenses (2089), for the ledger-based refusal of
     * S1-R10.3 (human decision H9, option b).
     * TODO(legal-verify): H9 — the accounts are from the BAS 2026 reference
     * linkbase (rule sheet S1-R10.3) and wait for a human to confirm them.
     */
    public const array DEVELOPMENT_ACCOUNTS = [[1010, 1019], [1081, 1081], [2089, 2089]];

    /** @var list<array{rule: string, message: string}> */
    private array $refusals = [];

    /** @var list<array{key: string, message: string}> */
    private array $questions = [];

    /** @var array<string, mixed> */
    private array $data = [];

    private ?K3Notes $notes = null;

    private function __construct(
        public readonly Company $company,
        public readonly FiscalYear $fiscalYear,
        public readonly AnnualReport $report,
    ) {}

    /**
     * Whether the year's annual report is this document: a K3 year of a
     * company whose legal form prepares an annual report in NONOX. Whether it
     * can be made is refusals()'s answer. The framework is the year's own
     * (ADR-059 A.2.1), the company's when no year is given.
     */
    public static function supports(Company $company, ?FiscalYear $fiscalYear = null): bool
    {
        $framework = $fiscalYear?->getAttribute('reporting_framework');
        $framework = $framework instanceof ReportingFramework ? $framework : $company->reporting_framework;

        return $framework === ReportingFramework::K3 && AnnualReportDocument::supportsLegalForm($company);
    }

    public static function for(Company $company, FiscalYear $fiscalYear, AnnualReport $report): self
    {
        if (! self::supports($company, $fiscalYear)) {
            throw new LogicException('The K3 annual report is for a K3 year.');
        }

        return CurrentCompany::run($company, function () use ($company, $fiscalYear, $report): self {
            $document = new self($company, $fiscalYear, $report);
            // The annual report is a Swedish document, as K3Notes and K3ManagementReport build it.
            $locale = App::getLocale();
            App::setLocale('sv');

            try {
                $document->build();
            } finally {
                App::setLocale($locale);
            }

            return $document;
        });
    }

    /**
     * Why NONOX does not make the year's K3 annual report, in Swedish with the
     * rule ids, or null when it does (ADR-059 A.3: the K3 reasons moved here
     * from AnnualReportDocument::refusal()).
     */
    public static function refusal(Company $company, FiscalYear $fiscalYear, ?AnnualReport $report = null): ?string
    {
        $refusals = self::for($company, $fiscalYear, $report ?? self::report($fiscalYear))->refusals();

        if ($refusals === []) {
            return null;
        }

        return implode(' ', array_map(fn (array $refusal): string => "{$refusal['message']} ({$refusal['rule']})", $refusals));
    }

    /** The year's annual report row, or an unsaved one with the defaults. */
    public static function report(FiscalYear $fiscalYear): AnnualReport
    {
        return AnnualReport::query()->where('fiscal_year_id', $fiscalYear->id)->first()
            ?? new AnnualReport(['fiscal_year_id' => $fiscalYear->id, 'signatories' => [], 'average_employees' => 0, 'dividend_ore' => 0]);
    }

    /**
     * Everything that keeps NONOX from making the report, each with the rule
     * id it follows. Empty when the report can be made.
     *
     * @return list<array{rule: string, message: string}>
     */
    public function refusals(): array
    {
        return $this->refusals;
    }

    /**
     * What a person still has to answer, from the notes and the management
     * report, in Swedish; a question already given as a refusal is not
     * repeated.
     *
     * @return list<array{key: string, message: string}>
     */
    public function questions(): array
    {
        return $this->questions;
    }

    /** Whether the report can be printed as a PDF: nothing refused, nothing left to answer. */
    public function complete(): bool
    {
        return $this->refusals === [] && $this->questions === [];
    }

    /** The notes, or null when the report is refused before they are built. */
    public function notes(): ?K3Notes
    {
        return $this->notes;
    }

    /**
     * The view data of the document; empty while the report is refused.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * The document as HTML. The preview ($preview) marks every note in a
     * person's own words and a draft with open questions; the PDF prints the
     * person's text without the marker (ADR-059 A.2.7).
     */
    public function html(bool $preview = true): string
    {
        if ($this->refusals !== []) {
            throw new LogicException('A refused K3 annual report has no document.');
        }

        $locale = App::getLocale();
        App::setLocale('sv');

        try {
            return view('annual-report.k3-document', [...$this->data, 'preview' => $preview])->render();
        } finally {
            App::setLocale($locale);
        }
    }

    public function pdf(): string
    {
        if (! $this->complete()) {
            throw new LogicException('The K3 annual report has open questions or refusals.');
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html(preview: false));
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    private function build(): void
    {
        if ($this->company->legal_form !== LegalForm::Ab) {
            $this->refuse(self::RULE_LEGAL_FORM, __('NONOX tar fram årsredovisningen enligt K3 för aktiebolag. En ekonomisk förening som tillämpar K3 kan inte få sin årsredovisning här ännu.'));

            return;
        }

        $previous = FiscalYear::query()->whereDate('end_date', '<', $this->fiscalYear->start_date->toDateString())->orderByDesc('end_date')->first();
        $firstK3Year = SaveFrameworkChoice::isFirstK3Year($this->fiscalYear, $previous);
        $size = $this->size();
        $policies = K3Policies::forYear($this->fiscalYear);

        match ($size->class) {
            K3SizeClass::Storre => $this->refuse('S1-R9.3', __('Företaget är ett större företag. NONOX tar ännu inte fram årsredovisningen enligt K3 för ett större företag (årsredovisningslagen 5 kap. 25–48 §§ och K3:s upplysningar för större företag); den delen kommer i ett senare steg (beslut S1-H1).')),
            K3SizeClass::Undetermined => $this->refuse('S1-R9', __('Det går inte att avgöra om företaget är ett mindre eller ett större företag: :reason Besvara frågorna om företagets storlek under Bokslut → Regelverk.', ['reason' => rtrim($size->reason, '.').'.'])),
            K3SizeClass::Mindre => null,
        };

        // S1-R1.4: only the 2025-12-15 consolidation (BFNAR 2025:3) and ÅRL as of SFS 2026:780 were read for S1, so an
        // earlier version (kons2024, years beginning 2024-07-01 to 2025-12-31) is refused, as K3Eligibility leaves those years unassessed.
        $version = K3Rules::entry('k3_version_by_fiscal_year', $this->fiscalYear->start_date, $this->fiscalYear->end_date)['value'] ?? null;

        if ($version !== self::K3_VERSION) {
            $this->refuse('S1-R1.4', __('Årsredovisningen tas bara fram för räkenskapsår som ska följa K3 i lydelsen enligt BFNAR 2025:3 (räkenskapsår som börjar efter den 31 december 2025). Räkenskapsåret :year följer en äldre lydelse, som NONOX inte har läst in.', ['year' => $this->fiscalYear->label()]));
        }

        $this->policyRefusals($policies, $firstK3Year);

        if ($size->class !== K3SizeClass::Mindre) {
            return;
        }

        $accounts = AnnualAccounts::for($this->fiscalYear, K3Schedule::class);
        $this->developmentRefusal($accounts, $policies);
        $this->setOffRefusal($accounts);

        $equity = K3EquityStatement::of($accounts);

        if ($equity->refusal() !== null) {
            $this->refuse('S1-R8.3', (string) $equity->refusal());
        }

        $cashFlow = $this->cashFlow($accounts, $policies, $size->class);
        $notes = K3Notes::for($this->company, $this->fiscalYear, $this->report, $size->class, $accounts, $policies);
        $this->notes = $notes;
        $management = K3ManagementReport::for($this->company, $this->fiscalYear, $this->report, $accounts, $size->class);
        $this->transitionRefusal($firstK3Year);

        foreach ($notes->notes() as $note) {
            if ($note->blocking !== null) {
                $this->refuse('S1-R8.1', $note->blocking);
            }
        }

        $this->collectQuestions($notes, $management);

        if ($this->refusals !== []) {
            return;
        }

        $this->data = $this->printed($accounts, $management, $cashFlow, $equity->columns());
    }

    /** The size test of the year (S1-R9), with the eligibility answers on listing and a newly started company, as K3Eligibility reads them. */
    private function size(): K3SizeClassification
    {
        $answers = K3EligibilityAnswers::forYear($this->fiscalYear);
        $first = $answers->answer(K3EligibilityQuestion::FirstYear) === true
            ? FiscalYear::query()->orderBy('start_date')->value('start_date')
            : null;

        return K3CompanySize::forFiscalYear($this->fiscalYear, $answers->answer(K3EligibilityQuestion::Listed) === true, $first?->toImmutable());
    }

    /** Policies missing for the year and policies S1 cannot report on (S1-R5, R6, R10, K3 10.2), each with its rule id. */
    private function policyRefusals(K3Policies $policies, bool $firstK3Year): void
    {
        foreach ($policies->missing(transition: $firstK3Year) as $key) {
            $this->refuse($key->rule(), __(':question Svara under Bokslut → Regelverk.', ['question' => rtrim($key->question(), ' ')]));
        }

        foreach ($policies->refusals() as $refusal) {
            $this->refuse($refusal['rule'], $refusal['message']);
        }

        // S1-R7.1: NONOX transitions without restating the comparatives only (S1-P12).
        if ($firstK3Year && $policies->value(K3PolicyKey::ComparativesRestatedAtTransition) === K3YesNo::Yes) {
            $this->refuse('S1-R7.1', __('Jämförelsetalen ska enligt principen räknas om vid övergången till K3, men NONOX räknar inte om dem ännu. Årsredovisningen kan tas fram när jämförelsetalen inte räknas om (K3 punkt 35.7).'));
        }
    }

    /**
     * S1-R10.3 (H9 b): under the expensing model, capitalised own development
     * or a fund for development expenses in the books contradicts the policy.
     */
    private function developmentRefusal(AnnualAccounts $accounts, K3Policies $policies): void
    {
        if ($policies->value(K3PolicyKey::Intangibles) !== K3Intangibles::Expense) {
            return;
        }

        $touched = [];

        foreach (self::DEVELOPMENT_ACCOUNTS as [$from, $to]) {
            foreach ($accounts->numbers() as $number) {
                if ($number >= $from && $number <= $to && ($accounts->closingOf($number, $number) !== 0 || $accounts->openingOf($number, $number) !== 0)) {
                    $touched[] = $number;
                }
            }
        }

        if ($touched === []) {
            return;
        }

        sort($touched);

        $this->refuse('S1-R10.3', __('Företaget har valt kostnadsföringsmodellen, men bokföringen har saldo på konto :accounts (aktiverade utvecklingsutgifter eller fond för utvecklingsutgifter). Aktiveringsmodellen stöds inte i NONOX (K3 punkt 18.7).', ['accounts' => implode(', ', array_values(array_unique($touched)))]));
    }

    /**
     * K3 29.32: with the right of set-off confirmed, deferred tax assets and
     * liabilities are shown net. The K3 balance sheet shows them gross, so a
     * confirmed set-off with both sides in the books is refused for now.
     */
    private function setOffRefusal(AnnualAccounts $accounts): void
    {
        $setOff = ClosingCheck::query()->where('fiscal_year_id', $this->fiscalYear->id)->where('key', K3TransitionProposal::SET_OFF_CHECK)->exists();

        if (! $setOff || $accounts->closingOf(1370, 1379) === 0 || $accounts->closingOf(2240, 2249) === 0) {
            return;
        }

        $this->refuse('S1-R4.6', __('Kvittningsrätt för uppskjuten skatt är bekräftad för året, men årsredovisningen visar ännu inte uppskjuten skattefordran och skatteskuld kvittade (K3 punkt 29.32).'));
    }

    /** The cash flow statement under the year's policy (S1-R2), or null when the report has none. */
    private function cashFlow(AnnualAccounts $accounts, K3Policies $policies, K3SizeClass $size): ?CashFlowStatement
    {
        try {
            $statement = CashFlowStatement::forYear($accounts, $policies, $size, $this->company->legal_form);
        } catch (CashFlowStatementRefused $refused) {
            $this->refuse('S1-R2', $refused->reason);

            return null;
        }

        if ($statement !== null && $statement->refusal() !== null) {
            $this->refuse('S1-R2.1', (string) $statement->refusal());

            return null;
        }

        return $statement;
    }

    /** S1-R7.7: a first K3 year after K2 needs its transition booked (S1-P12) before the report is made. */
    private function transitionRefusal(bool $firstK3Year): void
    {
        $note = $this->notes?->note('first_time_application');

        if ($firstK3Year && $note !== null && $note->data['booked'] !== true) {
            $this->refuse('S1-R7.7', __('Det här är det första räkenskapsåret enligt K3, och övergången från K2 är inte bokförd. Bokför den under Bokslut → Övergång till K3.'));
        }
    }

    private function collectQuestions(K3Notes $notes, K3ManagementReport $management): void
    {
        $refused = array_column($this->refusals, 'message');
        $policyQuestions = array_map(fn (K3PolicyKey $key): string => $key->question(), K3PolicyKey::cases());
        $transition = __('Övergången till K3 är inte bokförd. Bokför den under Bokslut → Övergång till K3.');

        foreach ([...$notes->questions(), ...$management->questions()] as $question) {
            $message = (string) $question['message'];

            // A question that a refusal already states (a policy, the transition, a dividend above free equity is kept).
            if (in_array($message, $refused, true) || in_array($message, $policyQuestions, true) || $message === $transition) {
                continue;
            }

            $this->questions[] = ['key' => (string) $question['key'], 'message' => $message];
        }
    }

    /**
     * The printed figures and the parts of the document (PrintedFigures with
     * the K3 schedule: whole kronor, sums of the rounded lines).
     *
     * @param  list<string>  $equityColumns  the columns of the equity statement (K3EquityStatement::columns())
     * @return array<string, mixed>
     */
    private function printed(AnnualAccounts $accounts, K3ManagementReport $management, ?CashFlowStatement $cashFlow, array $equityColumns): array
    {
        $sections = collect($management->sections())->keyBy('key');
        $multiYear = $sections['multi_year']['data']['rows'] ?? $accounts->multiYear();

        try {
            $printed = PrintedFigures::apply([
                'income' => $accounts->incomeStatement(),
                'hasPreviousIncome' => $accounts->previousYear !== null,
                'balance' => $accounts->balanceSheet(),
                'multiYear' => $multiYear,
                'netSalesVariations' => [],
                'equityChanges' => $accounts->equityChanges(),
                'disposition' => ['dividend' => $this->report->dividend_ore->ore],
                'fixedAssets' => $accounts->fixedAssetNotes(),
                'pledged' => [],
                'contingent' => null,
            ], K3Schedule::class);
            $cashFlowRows = $cashFlow === null ? null : CashFlowPrintedFigures::apply($cashFlow->rows());
        } catch (UnbalancedBalanceSheet $unbalanced) {
            $this->refuse('S1-R8.2', __('Balansräkningen går inte ihop: tillgångarna och eget kapital och skulder skiljer sig med :amount, mer än avrundning till hela kronor kan förklara. Oftast finns ett saldo på ett konto som ingen rad i balansräkningen tar.', ['amount' => Money::ofOre(abs($unbalanced->difference))->format()]));

            return [];
        } catch (K3EquityStatementRefused $refused) {
            $this->refuse('S1-R8.3', $refused->reason);

            return [];
        } catch (CashFlowStatementRefused $refused) {
            $this->refuse('S1-R2.1', $refused->reason);

            return [];
        }

        $notes = $this->notes?->notes() ?? [];

        return [
            'company' => $this->company,
            'fiscalYear' => $this->fiscalYear,
            'report' => $this->report,
            'previousLabel' => $accounts->previousYear?->label() ?? $this->fiscalYear->start_date->subDay()->toDateString(),
            'hasPreviousIncome' => $accounts->previousYear !== null,
            'income' => $printed['income'],
            'balance' => $printed['balance'],
            'groups' => K3Schedule::groupTotals($printed['balance']),
            'taxSpecification' => $this->taxSpecification($accounts),
            'equityRows' => $printed['equityChanges'],
            'equityColumns' => array_map(fn (string $column): array => ['key' => $column, 'label' => K3EquityStatement::columnLabel($column)], $equityColumns),
            'cashFlow' => $cashFlowRows,
            'management' => $sections->except(['multi_year', 'disposition'])->values()->all(),
            'multiYear' => $printed['multiYear'],
            'multiYearParagraphs' => $sections['multi_year']['paragraphs'] ?? [],
            'disposition' => $printed['disposition'],
            'notes' => $notes,
            'fixedAssets' => is_array($printed['fixedAssets']) ? array_column($printed['fixedAssets'], null, 'key') : [],
            'noteNumbers' => self::noteNumbers($notes),
            'certificate' => AnnualReportDocument::certificate($this->company, $this->fiscalYear, $this->report, $accounts->freeEquity()),
            'draft' => $this->questions !== [],
        ];
    }

    /**
     * The deferred tax part of Avsättningar för skatter, shown as "varav
     * uppskjuten skatt" under the line (ÅRL bilaga 1 C.2; ADR-059, S1-P12
     * re-check P3-a), or null when neither year has any.
     *
     * @return ?array{label: string, amount: int, previous: int}
     */
    private function taxSpecification(AnnualAccounts $accounts): ?array
    {
        [$from, $to] = K3Schedule::DEFERRED_TAX_SPECIFICATION['ranges'][0];
        // A liability is a credit balance; the balance sheet prints it positive.
        $amount = PrintedFigures::round(-$accounts->closingOf($from, $to));
        // Opening balances equal the previous year's closing ones (IB = UB, ADR-059 H7).
        $previous = PrintedFigures::round(-$accounts->openingOf($from, $to));

        if ($amount === 0 && $previous === 0) {
            return null;
        }

        return ['label' => __(K3Schedule::DEFERRED_TAX_SPECIFICATION['label']), 'amount' => $amount, 'previous' => $previous];
    }

    /**
     * The note number shown beside a statement line: each fixed asset line its
     * note, the personnel costs the employees note, the equity sections the
     * split into bound and free equity, the long-term liabilities their note.
     *
     * @param  list<K3Note>  $notes
     * @return array<string, int>
     */
    private static function noteNumbers(array $notes): array
    {
        $numbers = [];

        foreach ($notes as $note) {
            match (true) {
                str_starts_with($note->key, 'fixed_assets.') => $numbers[substr($note->key, strlen('fixed_assets.'))] = $note->number,
                $note->key === 'average_employees' => $numbers['personnel'] = $note->number,
                $note->key === 'equity_split' => $numbers['section.restricted'] = $numbers['section.free'] = $note->number,
                $note->key === 'long_term_liabilities' => $numbers['section.long_term'] = $note->number,
                default => null,
            };
        }

        return $numbers;
    }

    private function refuse(string $rule, string $message): void
    {
        foreach ($this->refusals as $refusal) {
            if ($refusal['message'] === $message) {
                return;
            }
        }

        $this->refusals[] = ['rule' => $rule, 'message' => $message];
    }

    public static function filename(Company $company, FiscalYear $fiscalYear): string
    {
        return 'Arsredovisning-K3-'.preg_replace('/\D/', '', (string) $company->org_number).'-'.$fiscalYear->end_date->format('Y').'.pdf';
    }
}
