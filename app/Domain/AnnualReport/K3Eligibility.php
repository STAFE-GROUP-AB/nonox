<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Company\LegalForm;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\CorporateGroup;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * May the company use K2 for a fiscal year, or must it use K3 (S1-R1,
 * ADR-059 addendum 2026-11-07, package S1-P11)?
 *
 * K2 may be chosen only when the company is a mindre företag (K2 1.1, from
 * the size test, S1-R9), none of K2 1.1A a–i applies, and K2 1.1B does not
 * apply or K2 1.1C lifts it. Sources: BFN, Vägledning BFNAR 2016:10, kons.
 * 2025, pp. 15–20, read 2026-11-07.
 *
 * - 1.1A: a (only an aktiebolag can be publikt), d (a natural person or an
 *   estate) and e (for an aktiebolag) follow from the legal form. NONOX has
 *   no data for b, c and f, and none for g–i (S1-R1.1: medium confidence,
 *   facts not in the ledger), so those are asked of a person.
 * - 1.1C first paragraph: computed from the two preceding years (S1-P2).
 *   BFN's comment: a company that can see that the exception applies need
 *   not assess 1.1B, so 1.1B is then not asked.
 * - 1.1C second paragraph: "a" from the previous year's framework in NONOX
 *   (asked when that year is not in NONOX); "b" asked with BFN's comment
 *   quoted verbatim (human decision H3, option a).
 * - 1.1B a: asked; NONOX shows the deferred tax as information only and
 *   never says whether it is "väsentlig" (human decision H2, option a).
 * - 1.1B b: computed only from the person's own classification of the
 *   revenue accounts of the assessment year, never guessed; asked when the
 *   assessment year is not kept in NONOX.
 *
 * NONOX never decides for the customer: an unanswered question leaves the
 * status Undetermined. A fixed number of queries, whatever the size of the
 * books.
 */
final class K3Eligibility
{
    /** @var list<array{key: string, rule: string, label: string, state: string, source: string, text: string, question: ?K3EligibilityQuestion}> */
    private array $items = [];

    /** @var list<string> */
    private array $reasons = [];

    /** @var list<string> */
    private array $missing = [];

    private function __construct(
        private readonly Company $company,
        private readonly FiscalYear $fiscalYear,
        private readonly K3EligibilityAnswers $answers,
        private readonly ?ReportingFramework $previousFramework = null,
    ) {}

    /**
     * $answers: the person's answers, saved ones when null; the screen passes
     * the form so the result shows what changes before anything is saved.
     * $previousFramework: the framework the preceding year will have, when a
     * change of framework is being judged before it is saved (1.1C second
     * paragraph a); null reads the preceding year as it is.
     */
    public static function forYear(FiscalYear $fiscalYear, ?K3EligibilityAnswers $answers = null, ?ReportingFramework $previousFramework = null): K3EligibilityResult
    {
        return CurrentCompany::run($fiscalYear->company_id, function () use ($fiscalYear, $answers, $previousFramework): K3EligibilityResult {
            $company = Company::query()->findOrFail($fiscalYear->company_id);

            return (new self($company, $fiscalYear, $answers ?? K3EligibilityAnswers::forYear($fiscalYear), $previousFramework))->evaluate();
        });
    }

    private function evaluate(): K3EligibilityResult
    {
        $legalForm = $this->company->legal_form;

        if (! in_array($legalForm, [LegalForm::Ab, LegalForm::EkonomiskForening], true)) {
            // TODO(legal-verify): which of K2 1.1A–1.1C apply to a handelsbolag, kommanditbolag, stiftelse or ideell förening that prepares an annual report.
            return $this->result(K3EligibilityStatus::NotAssessed, __('NONOX bedömer K2 punkt 1.1–1.1C bara för aktiebolag och ekonomiska föreningar. Företagsformen är :form; villkoren visas när regelverket väljs.', ['form' => mb_strtolower($legalForm->label())]));
        }

        $earliest = FiscalYear::query()->orderBy('start_date')->first();
        $firstYearStart = $earliest?->start_date;
        $confirmedFirst = $this->answers->answer(K3EligibilityQuestion::FirstYear) === true ? $firstYearStart : null;
        $start = $this->fiscalYear->start_date;
        $end = $this->fiscalYear->end_date;
        $isFirstYear = $confirmedFirst !== null && $confirmedFirst->isSameDay($start);

        $years = K3CompanySize::priorYears($this->fiscalYear);
        $contradiction = K3CompanySize::contradictedConfirmation($this->fiscalYear, $confirmedFirst);
        $listed = $this->answers->answer(K3EligibilityQuestion::Listed) === true;
        $size = $contradiction !== null
            ? new K3SizeClassification(K3SizeClass::Undetermined, $contradiction, 'S1-R9.1')
            : K3CompanySize::classify($years, $start, $listed, $confirmedFirst, $legalForm);
        $needed = K3Rules::int('larger_company.prior_years', $start);
        $missingSizeYear = $this->missingSizeYear($years, $needed, $earliest);

        $this->sizeItems($size, count($years) < $needed || $this->answers->answer(K3EligibilityQuestion::FirstYear) !== null, $firstYearStart);

        if (K3Rules::entry('k2_1_1c.employees_gt', $start, $end, $isFirstYear) === null) {
            // S1-R1.4: the 2025:2 wording applies to years beginning after 2025-12-31 (or an extended first year).
            // TODO(legal-verify): the K2 1.1 wording for fiscal years beginning before 2026-01-01 (kons2024) is not read.
            if ($size->class === K3SizeClass::Storre) {
                $this->reasons[] = $size->reason;

                return $this->result(K3EligibilityStatus::K3Required, __('Företaget är ett större företag och ska tillämpa K3.'), $size, null, null, $missingSizeYear, $firstYearStart);
            }

            return $this->result(K3EligibilityStatus::NotAssessed, __('K2 punkt 1.1A–1.1C i lydelsen enligt BFNAR 2025:2 gäller räkenskapsår som börjar efter den 31 december 2025. För ett tidigare räkenskapsår gäller en äldre lydelse, som NONOX inte bedömer; det gör en människa.'), $size, null, null, $missingSizeYear, $firstYearStart);
        }

        $exception = $contradiction !== null
            ? new K3K2Exception(null, $contradiction)
            : K3CompanySize::k2Exception($years, $start, $end, $confirmedFirst, $legalForm);

        $this->exclusionItems($legalForm);
        $building = $this->k2BusinessConditions($exception);

        return $this->result($this->status($size), $this->summary($size), $size, $exception, $building, $missingSizeYear, $firstYearStart);
    }

    private function sizeItems(K3SizeClassification $size, bool $askFirstYear, ?CarbonImmutable $firstYearStart): void
    {
        $this->ask(K3EligibilityQuestion::Listed);

        if ($askFirstYear && $firstYearStart !== null) {
            $answer = $this->answers->answer(K3EligibilityQuestion::FirstYear);
            $this->items[] = [
                'key' => K3EligibilityQuestion::FirstYear->value,
                'rule' => K3EligibilityQuestion::FirstYear->rule(),
                'label' => K3EligibilityQuestion::FirstYear->label(),
                'state' => $answer === null ? 'unknown' : ($answer ? 'applies' : 'not'),
                'source' => $answer === null ? 'missing' : 'answer',
                'text' => __('Ett nystartat företag är mindre de två första räkenskapsåren och får tillämpa K2 dem om punkt 1.1A inte hindrar det (BFN:s kommentarer till BFNAR 2006:11 punkt 6 och K2 punkt 1.1C).'),
                'question' => K3EligibilityQuestion::FirstYear,
            ];
        }

        $this->items[] = [
            'key' => 'size',
            'rule' => $size->ruleId,
            'label' => __('Företagets storlek'),
            'state' => match ($size->class) {
                K3SizeClass::Storre => 'applies',
                K3SizeClass::Mindre => 'not',
                K3SizeClass::Undetermined => 'unknown',
            },
            'source' => $size->class === K3SizeClass::Undetermined ? 'missing' : 'data',
            'text' => $size->reason,
            'question' => null,
        ];

        if ($size->class === K3SizeClass::Storre) {
            $this->reasons[] = $size->reason;
        }

        if ($size->class === K3SizeClass::Undetermined) {
            $this->missing[] = $size->reason;
        }
    }

    private function exclusionItems(LegalForm $legalForm): void
    {
        $isAb = $legalForm === LegalForm::Ab;

        if ($isAb) {
            $this->ask(K3EligibilityQuestion::PublicCompany);
        } else {
            $this->fact('k2_1_1a_a', 'K2 1.1A a', __('Publikt aktiebolag'), __('Bara ett aktiebolag kan vara ett publikt aktiebolag. Företagsformen är :form.', ['form' => mb_strtolower($legalForm->label())]));
        }

        $isParent = CorporateGroup::query()->where('parent_company_id', $this->company->id)->exists();
        $groupHint = $isParent
            ? (string) __('Företaget är moderföretag i en koncern som är registrerad i NONOX. Om koncernen är större eller om företaget upprättar koncernredovisning avgör ni.')
            : null;

        $this->ask(K3EligibilityQuestion::ParentLargerGroup, hint: $groupHint);
        $this->ask(K3EligibilityQuestion::ParentGroupAccounts, hint: $groupHint);
        $this->fact('k2_1_1a_d', 'K2 1.1A d', __('Fysisk person eller dödsbo'), __('Företagsformen är :form, inte en fysisk person eller ett dödsbo.', ['form' => mb_strtolower($legalForm->label())]));

        if ($isAb) {
            $this->fact('k2_1_1a_e', 'K2 1.1A e', __('Bostadsrättsförening eller bostadsförening'), __('Ett aktiebolag är inte en bostadsrättsförening eller en bostadsförening.'));
        } else {
            $this->ask(K3EligibilityQuestion::HousingCooperative);
        }

        foreach ([K3EligibilityQuestion::ForeignBranch, K3EligibilityQuestion::ShareBasedPayment, K3EligibilityQuestion::CompoundInstruments, K3EligibilityQuestion::CryptoAssets] as $question) {
            $this->ask($question);
        }
    }

    /**
     * K2 1.1B and the second paragraph of 1.1C, after the first paragraph of
     * 1.1C. Returns the building test's facts when the assessment year is
     * kept in NONOX.
     *
     * @return ?array{year: string, accounts: list<array{number: int, name: string, amount_ore: int, selected: bool}>, net_sales_ore: int, building_ore: int, threshold_bp: int, applies: ?bool, classified: bool}
     */
    private function k2BusinessConditions(K3K2Exception $exception): ?array
    {
        $this->items[] = [
            'key' => 'k2_1_1c_first',
            'rule' => 'K2 1.1C första stycket',
            'label' => __('Undantag för de minsta företagen'),
            'state' => match ($exception->applies) {
                true => 'applies',
                false => 'not',
                null => 'unknown',
            },
            'source' => $exception->applies === null ? 'missing' : 'data',
            'text' => $exception->reason,
            'question' => null,
        ];

        if ($exception->applies === true) {
            // BFN's comment to 1.1C: a company that can see that the first exception applies need not assess 1.1B.
            foreach ([K3EligibilityQuestion::DeferredTax, K3EligibilityQuestion::BuildingRevenue] as $question) {
                $this->notNeeded($question->value, $question->rule(), $question->label(), __('Behöver inte bedömas: undantaget i punkt 1.1C första stycket gäller.'));
            }

            return null;
        }

        if ($exception->applies === null) {
            $this->missing[] = $exception->reason;
        }

        $previous = FiscalYear::query()->whereDate('end_date', $this->fiscalYear->start_date->subDay()->toDateString())->first();

        if ($previous instanceof FiscalYear) {
            $previousFramework = $this->previousFramework ?? $previous->reporting_framework;
            $previousK2 = $previousFramework === ReportingFramework::K2;
            $this->fact('k2_1_1c_prev', K3EligibilityQuestion::PreviousYearK2->rule(), K3EligibilityQuestion::PreviousYearK2->label(), $previousK2
                ? __('Räkenskapsåret :year har K2 i NONOX.', ['year' => $previous->label()])
                : __('Räkenskapsåret :year har :framework i NONOX, inte K2. Undantaget i punkt 1.1C andra stycket gäller bara ett företag som tillämpade K2 föregående år.', ['year' => $previous->label(), 'framework' => $previousFramework->value]), $previousK2 ? 'applies' : 'not');
        } else {
            $previousK2 = $this->ask(K3EligibilityQuestion::PreviousYearK2, required: false);
        }

        $normally = $previousK2 === false ? null : $this->ask(K3EligibilityQuestion::NormallyNotCovered, required: false, hint: __('Undantaget låter ett företag som redan tillämpar K2 fortsätta med det när en förändring gör att det omfattas av punkt 1.1B, om det inte gäller mer än något eller några enstaka år.'));
        $deferredTax = $this->ask(K3EligibilityQuestion::DeferredTax, required: false, hint: self::deferredTaxInfo());
        [$buildingApplies, $building, $buildingMissing] = $this->buildingTest();

        $covered = match (true) {
            $deferredTax === true || $buildingApplies === true => true,
            $deferredTax === false && $buildingApplies === false => false,
            default => null,
        };

        if ($previousK2 === true && $normally === true) {
            // Lifted by the second exception of 1.1C whatever 1.1B says.
            return $building;
        }

        if ($covered === null) {
            if ($deferredTax === null) {
                $this->missing[] = self::missingText(K3EligibilityQuestion::DeferredTax);
            }

            if ($buildingMissing !== null) {
                $this->missing[] = $buildingMissing;
            }

            return $building;
        }

        if ($covered === false) {
            return $building;
        }

        if ($previousK2 === null) {
            $this->missing[] = self::missingText(K3EligibilityQuestion::PreviousYearK2);

            return $building;
        }

        if ($previousK2 === true && $normally === null) {
            $this->missing[] = self::missingText(K3EligibilityQuestion::NormallyNotCovered);

            return $building;
        }

        if ($exception->applies === null) {
            // 1.1B applies by the answers, but whether the first exception of 1.1C lifts it is not known: never K3 on a guess.
            $this->missing[] = (string) __('Enligt era svar omfattas företaget av K2 punkt 1.1B, men om undantaget i punkt 1.1C första stycket gäller kan inte avgöras förrän uppgifterna ovan finns.');

            return $building;
        }

        $this->reasons[] = $deferredTax === true
            ? __('Företaget har enligt ert svar en väsentlig uppskjuten skatteskuld (K2 punkt 1.1B a), och inget av undantagen i punkt 1.1C gäller.')
            : __('Byggnader genererar minst 75 procent av nettoomsättningen enligt er bedömning (K2 punkt 1.1B b), och inget av undantagen i punkt 1.1C gäller.');

        return $building;
    }

    /**
     * K2 1.1B b from the person's classification of the revenue accounts of
     * the latest year with a completed annual report before this one.
     *
     * TODO(legal-verify): NONOX treats an annual report as "färdigställd" when its signing date is filled in.
     *
     * @return array{0: ?bool, 1: ?array{year: string, accounts: list<array{number: int, name: string, amount_ore: int, selected: bool}>, net_sales_ore: int, building_ore: int, threshold_bp: int, applies: ?bool, classified: bool}, 2: ?string}
     */
    private function buildingTest(): array
    {
        $threshold = K3Rules::int('k2_1_1b.building_turnover_share_min_bp', $this->fiscalYear->start_date, $this->fiscalYear->end_date, true);
        $assessment = FiscalYear::query()
            ->whereDate('end_date', '<', $this->fiscalYear->start_date->toDateString())
            ->whereIn('id', AnnualReport::query()->whereNotNull('signing_date')->select('fiscal_year_id'))
            ->orderByDesc('end_date')
            ->first();

        if (! $assessment instanceof FiscalYear) {
            $answer = $this->ask(K3EligibilityQuestion::BuildingRevenue, required: false, hint: __('Det senaste räkenskapsåret med en färdigställd årsredovisning finns inte i NONOX, så bedömningen görs av er. Intäkter från uthyrning av bostäder eller lokaler och från rumsuthyrning i hotell räknas; en byggnad för tillverkning, varuförsäljning eller enbart tjänster (till exempel en restaurang) gör det inte (BFN:s kommentar till punkt 1.1B b).'));

            return [$answer, null, $answer === null ? self::missingText(K3EligibilityQuestion::BuildingRevenue) : null];
        }

        $accounts = new AnnualAccounts($assessment, null);
        $numbers = array_values(array_filter($accounts->numbers(), fn (int $number): bool => $number >= 3000 && $number <= 3799));
        sort($numbers);
        $names = Account::query()->whereIn('number', $numbers)->pluck('name', 'number');
        $netSales = $accounts->netSales();
        $selected = array_intersect($this->answers->buildingAccounts, $numbers);
        $rows = [];
        $buildingOre = 0;

        foreach ($numbers as $number) {
            $amount = -$accounts->movementOf($number, $number);
            $isSelected = in_array($number, $selected, true);
            $buildingOre += $isSelected ? $amount : 0;
            $rows[] = ['number' => $number, 'name' => (string) ($names[$number] ?? ''), 'amount_ore' => $amount, 'selected' => $isSelected];
        }

        $classified = $this->answers->buildingClassified;
        $applies = ! $classified || $netSales <= 0 ? null : $buildingOre * 10_000 >= $threshold * $netSales;
        $building = ['year' => $assessment->label(), 'accounts' => $rows, 'net_sales_ore' => $netSales, 'building_ore' => $buildingOre, 'threshold_bp' => $threshold, 'applies' => $applies, 'classified' => $classified];

        $text = (string) match (true) {
            ! $classified => __('Markera de intäktskonton för :year som byggnaderna genererar, till exempel hyror och rumsuthyrning. NONOX gissar inte vilka intäkter som kommer från en byggnad.', ['year' => $assessment->label()]),
            $netSales <= 0 => __('Nettoomsättningen för :year är noll eller negativ, så andelen kan inte räknas ut.', ['year' => $assessment->label()]),
            $applies === true => __('Enligt er klassificering genererar byggnaderna :share procent av nettoomsättningen :year, minst 75 procent.', ['share' => self::percent($buildingOre, $netSales), 'year' => $assessment->label()]),
            default => __('Enligt er klassificering genererar byggnaderna :share procent av nettoomsättningen :year, mindre än 75 procent.', ['share' => self::percent($buildingOre, $netSales), 'year' => $assessment->label()]),
        };

        $this->items[] = [
            'key' => K3EligibilityQuestion::BuildingRevenue->value,
            'rule' => K3EligibilityQuestion::BuildingRevenue->rule(),
            'label' => K3EligibilityQuestion::BuildingRevenue->label(),
            'state' => $applies === null ? 'unknown' : ($applies ? 'applies' : 'not'),
            'source' => $applies === null ? 'missing' : 'answer',
            'text' => $text,
            'question' => null,
        ];

        return [$applies, $building, $applies === null ? $text : null];
    }

    /** The K2 1.1B a text and what NONOX shows about deferred tax (human decision H2, option a). */
    public static function deferredTaxInfo(): string
    {
        return __('K2 punkt 1.1B a: ”Företag som har skatt som är väsentlig och som beror på att tillgångars redovisade värde är högre än tillgångars skattemässiga värde eller på att avsättningars eller skulders redovisade värde är lägre än avsättningars och skulders skattemässiga värde (uppskjuten skatteskuld).” BFN:s kommentar: ”Skillnader som redovisas som obeskattade reserver, t.ex. överavskrivningar på inventarier, ska inte tas med vid bedömningen.” NONOX räknar ännu inte fram den uppskjutna skatten; när beräkningen finns visas beloppet här, bara som information. Om skulden är väsentlig bedömer ni – NONOX föreslår aldrig något svar.');
    }

    private function status(K3SizeClassification $size): K3EligibilityStatus
    {
        if ($this->reasons !== []) {
            return K3EligibilityStatus::K3Required;
        }

        if ($this->missing !== [] || $size->class !== K3SizeClass::Mindre) {
            return K3EligibilityStatus::Undetermined;
        }

        return K3EligibilityStatus::K2Allowed;
    }

    private function summary(K3SizeClassification $size): string
    {
        return match ($this->status($size)) {
            K3EligibilityStatus::K3Required => __('Företaget ska tillämpa K3 för räkenskapsåret :year.', ['year' => $this->fiscalYear->label()]),
            K3EligibilityStatus::K2Allowed => __('Enligt uppgifterna och era svar får företaget välja K2 för räkenskapsåret :year. K3 får alltid väljas.', ['year' => $this->fiscalYear->label()]),
            default => __('Svara på frågorna och fyll i det som saknas, så visar NONOX om företaget får välja K2 för :year.', ['year' => $this->fiscalYear->label()]),
        };
    }

    /**
     * Adds a question; a yes on a condition that closes K2 adds a reason, and
     * a missing answer to a required question adds to the missing list.
     * Returns the answer.
     */
    private function ask(K3EligibilityQuestion $question, bool $required = true, ?string $hint = null): ?bool
    {
        $answer = $this->answers->answer($question);

        $this->items[] = [
            'key' => $question->value,
            'rule' => $question->rule(),
            'label' => $question->label(),
            'state' => $answer === null ? 'unknown' : ($answer ? 'applies' : 'not'),
            'source' => $answer === null ? 'missing' : 'answer',
            'text' => (string) $hint,
            'question' => $question,
        ];

        if ($answer === null && $required) {
            $this->missing[] = self::missingText($question);
        }

        if ($answer === true && $question->closesK2()) {
            $this->reasons[] = __('Enligt ert svar gäller :rule (:label). Företaget får då inte tillämpa K2.', ['rule' => $question->rule(), 'label' => mb_strtolower($question->label())]);
        }

        return $answer;
    }

    private static function missingText(K3EligibilityQuestion $question): string
    {
        return __(':rule: :label – frågan är inte besvarad.', ['rule' => $question->rule(), 'label' => $question->label()]);
    }

    private function fact(string $key, string $rule, string $label, string $text, string $state = 'not'): void
    {
        $this->items[] = ['key' => $key, 'rule' => $rule, 'label' => $label, 'state' => $state, 'source' => 'data', 'text' => $text, 'question' => null];
    }

    private function notNeeded(string $key, string $rule, string $label, string $text): void
    {
        $this->items[] = ['key' => $key, 'rule' => $rule, 'label' => $label, 'state' => 'not_needed', 'source' => 'not_needed', 'text' => $text, 'question' => null];
    }

    /**
     * The year before the company's first year in NONOX whose size figures
     * are missing, when it is one a person may enter (12 months assumed; the
     * form lets the person change the dates).
     *
     * @param  list<K3SizeFigures>  $years
     * @return ?array{start_date: string, end_date: string}
     */
    private function missingSizeYear(array $years, int $needed, ?FiscalYear $earliest): ?array
    {
        if (count($years) >= $needed || $earliest === null) {
            return null;
        }

        $end = (count($years) === 0 ? $this->fiscalYear->start_date : $years[count($years) - 1]->start)->subDay();

        if (! $end->lessThan($earliest->start_date)) {
            return null;
        }

        return ['start_date' => $end->subMonthsNoOverflow(12)->addDay()->toDateString(), 'end_date' => $end->toDateString()];
    }

    private static function percent(int $part, int $whole): string
    {
        return number_format(intdiv($part * 1000, max($whole, 1)) / 10, 1, ',', ' ');
    }

    /**
     * @param  ?array{year: string, accounts: list<array{number: int, name: string, amount_ore: int, selected: bool}>, net_sales_ore: int, building_ore: int, threshold_bp: int, applies: ?bool, classified: bool}  $building
     * @param  ?array{start_date: string, end_date: string}  $missingSizeYear
     */
    private function result(K3EligibilityStatus $status, string $summary, ?K3SizeClassification $size = null, ?K3K2Exception $exception = null, ?array $building = null, ?array $missingSizeYear = null, ?CarbonImmutable $firstYearStart = null): K3EligibilityResult
    {
        return new K3EligibilityResult($status, $summary, $this->items, $this->reasons, $this->missing, $size, $exception, $building, self::deferredTaxInfo(), $missingSizeYear, $firstYearStart?->toDateString());
    }
}
