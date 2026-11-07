<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Domain\Company\LegalForm;
use App\Models\AnnualReport;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\PriorYearSizeFigure;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The size test (ADR-059, addendum 2026-11-07, S1-P2): is the company a
 * större or a mindre företag under ÅRL 1 kap. 3 § första stycket 4–5
 * (S1-R9), measured as BFNAR 2006:11 and BFN's vägledning Gränsvärden
 * (uppdaterad 2026-09-14) say, and does the first paragraph of K2 1.1C lift
 * K2 1.1B (S1-R1.3)?
 *
 * - The measured years are the two that come immediately before the
 *   reporting year (point 6).
 * - A company is större when the same two thresholds are exceeded in both
 *   years (vägledning, examples 1 and 2).
 * - Every threshold is strict ("mer än"). Net turnover is compared adjusted
 *   to twelve months, exactly; the balance sheet total is not adjusted
 *   (point 5). The average number of employees is rounded to a whole
 *   number before the comparison (vägledning, example 3).
 * - A newly started company is mindre its first two years (comment to
 *   point 6), but only once a person has confirmed when its first fiscal
 *   year began: missing figures alone never make a company mindre.
 * - An ideell förening or a stiftelse adds gifts, grants and membership fees
 *   to its net turnover (ÅRL 1 kap. 3 § tredje stycket); NONOX does not
 *   compute that, so its size is left to a person.
 *
 * The thresholds and these readings are data (K3Rules). NONOX never
 * guesses: the answer is undetermined when figures are missing or a missing
 * employee figure would change it.
 */
final class K3CompanySize
{
    /**
     * Classifies the company for a reporting year that begins on
     * $reportingStart, from the figures of the preceding years, newest first.
     *
     * $listed: the caller must have a person's confirmation that the
     * company's securities are admitted to trading on a regulated market;
     * NONOX has no field for it. $confirmedFirstYearStart: the start of the
     * company's first fiscal year, as a person has confirmed it.
     *
     * @param  list<K3SizeFigures>  $years
     */
    public static function classify(array $years, CarbonImmutable $reportingStart, bool $listed = false, ?CarbonImmutable $confirmedFirstYearStart = null, ?LegalForm $legalForm = null): K3SizeClassification
    {
        if ($listed) {
            return new K3SizeClassification(K3SizeClass::Storre, __('Större företag: enligt bekräftelsen är företagets värdepapper upptagna till handel på en reglerad marknad (ÅRL 1 kap. 3 § första stycket 4).'), 'S1-R9');
        }

        /** @var list<string> $additionForms */
        $additionForms = K3Rules::rule('larger_company.turnover_additions_legal_forms', $reportingStart)['value'];

        if ($legalForm !== null && in_array($legalForm->value, $additionForms, true)) {
            return new K3SizeClassification(K3SizeClass::Undetermined, __('Är företaget ett större företag? Företagsformen är :form. För en ideell förening eller en stiftelse ska bidrag, gåvor, medlemsavgifter och andra liknande intäkter läggas till nettoomsättningen, och anställda som fått högst ett halvt prisbasbelopp räknas inte (ÅRL 1 kap. 3 § tredje stycket, BFNAR 2006:11 punkterna 11 och 11A). Det räknar NONOX inte ut, så storleken avgörs av en människa.', ['form' => mb_strtolower($legalForm->label())]), 'S1-R9');
        }

        $needed = K3Rules::int('larger_company.prior_years', $reportingStart);
        $missing = self::firstMissingYearEnd($years, $reportingStart, $needed);

        if ($missing !== null) {
            if (self::newlyStarted($missing, $confirmedFirstYearStart) && K3Rules::rule('larger_company.newly_started_is_mindre', $reportingStart)['value'] === true) {
                return new K3SizeClassification(K3SizeClass::Mindre, __('Mindre företag: företaget är nystartat – dess första räkenskapsår började :date enligt bekräftelsen – och ett nystartat företag är mindre de två första åren (BFN:s vägledning Gränsvärden, kommentaren till punkt 6).', ['date' => $confirmedFirstYearStart?->toDateString()]), 'S1-R9.1');
            }

            return new K3SizeClassification(K3SizeClass::Undetermined, __('Hur stort var företaget under räkenskapsåret som slutade :date? Storleken bedöms på de två räkenskapsår som närmast föregår årsredovisningens år, och för det året saknas uppgifter. Är företaget nystartat behöver NONOX en bekräftelse av när dess första räkenskapsår började.', ['date' => $missing->toDateString()]), 'S1-R9.1');
        }

        if (K3Rules::rule('larger_company.same_criteria_both_years', $reportingStart)['value'] !== true) {
            throw new LogicException('Only the reading "the same two thresholds in both years" is implemented.');
        }

        $years = array_slice($years, 0, $needed);
        $thresholds = [
            K3SizeCriterion::Employees->value => K3Rules::int('larger_company.employees_gt', $reportingStart),
            K3SizeCriterion::BalanceSheetTotal->value => K3Rules::int('larger_company.balance_sheet_total_gt_ore', $reportingStart),
            K3SizeCriterion::NetTurnover->value => K3Rules::int('larger_company.net_turnover_gt_ore', $reportingStart),
        ];
        $criteriaNeeded = K3Rules::int('larger_company.criteria_needed', $reportingStart);
        $outcomes = [];

        foreach (self::worlds($years, $thresholds) as $world) {
            $outcomes[] = count(self::metInEveryYear($world)) >= $criteriaNeeded ? K3SizeClass::Storre->value : K3SizeClass::Mindre->value;
        }

        $outcomes = array_values(array_unique($outcomes));
        $names = self::names($years);
        $exceeded = [];

        foreach ($years as $figures) {
            $exceeded[$figures->key()] = self::knownExceeded($figures, $thresholds);
        }

        if (count($outcomes) > 1) {
            $unknown = array_values(array_map(fn (K3SizeFigures $figures): string => $names[$figures->key()], array_filter($years, fn (K3SizeFigures $figures): bool => $figures->averageEmployees === null)));

            return new K3SizeClassification(K3SizeClass::Undetermined, __('Vad var medelantalet anställda under :years? Uppgiften avgör om företaget är ett större företag.', ['years' => self::joinWords(array_reverse($unknown))]), 'S1-R9.2', $exceeded, $years);
        }

        $replace = ['years' => self::joinWords(array_reverse(array_values($names))), 'employees' => self::employeesText($years, $names)];

        if ($outcomes[0] === K3SizeClass::Storre->value) {
            $met = self::metCriteria($years, $thresholds);

            return new K3SizeClassification(K3SizeClass::Storre, __('Större företag: samma gränsvärden – :criteria – har överskridits under vart och ett av räkenskapsåren :years (ÅRL 1 kap. 3 § första stycket 4). Medelantal anställda: :employees.', ['criteria' => self::criteriaWords($met)] + $replace), 'S1-R9', $exceeded, $years);
        }

        return new K3SizeClassification(K3SizeClass::Mindre, __('Mindre företag: högst ett av gränsvärdena har överskridits under vart och ett av räkenskapsåren :years (ÅRL 1 kap. 3 § första stycket 5). Medelantal anställda: :employees.', $replace), 'S1-R9', $exceeded, $years);
    }

    /**
     * The first paragraph of K2 1.1C (S1-R1.3) for a fiscal year from $start
     * to $end: the exception applies when at most one criterion is exceeded
     * in each of the two preceding years. BFN's comment: exceeding the same
     * two thresholds in both years removes it, and a newly started company
     * may use K2 its first two years. $confirmedFirstYearStart is the start
     * of the company's first fiscal year as a person has confirmed it; it
     * also decides whether this is an extended first year (BFNAR 2025:2).
     *
     * @param  list<K3SizeFigures>  $years  newest first
     */
    public static function k2Exception(array $years, CarbonImmutable $start, CarbonImmutable $end, ?CarbonImmutable $confirmedFirstYearStart = null): K3K2Exception
    {
        $firstYear = $confirmedFirstYearStart !== null && $confirmedFirstYearStart->isSameDay($start);

        if (K3Rules::entry('k2_1_1c.employees_gt', $start, $end, $firstYear) === null) {
            return new K3K2Exception(null, __('NONOX har K2 punkt 1.1C i den lydelse som gäller räkenskapsår som börjar efter den 31 december 2025. För ett tidigare räkenskapsår bedömer en människa undantaget.'));
        }

        // BFN's comment to K2 1.1C: the two years that come immediately before, as BFNAR 2006:11 point 6.
        $needed = K3Rules::int('larger_company.prior_years', $start);
        $missing = self::firstMissingYearEnd($years, $start, $needed);

        if ($missing !== null) {
            if (self::newlyStarted($missing, $confirmedFirstYearStart)) {
                return new K3K2Exception(true, __('Undantaget i K2 punkt 1.1C gäller: företaget är nystartat – dess första räkenskapsår började :date enligt bekräftelsen – och får enligt BFN:s kommentar tillämpa K2 de två första räkenskapsåren om punkt 1.1A inte hindrar det.', ['date' => $confirmedFirstYearStart?->toDateString()]));
            }

            return new K3K2Exception(null, __('För räkenskapsåret som slutade :date saknas uppgifter, och undantaget i K2 punkt 1.1C bedöms på de två närmast föregående räkenskapsåren. Är företaget nystartat behöver NONOX en bekräftelse av när dess första räkenskapsår började.', ['date' => $missing->toDateString()]));
        }

        $years = array_slice($years, 0, $needed);
        $thresholds = [
            K3SizeCriterion::Employees->value => K3Rules::int('k2_1_1c.employees_gt', $start, $end, $firstYear),
            K3SizeCriterion::BalanceSheetTotal->value => K3Rules::int('k2_1_1c.balance_sheet_total_gt_ore', $start, $end, $firstYear),
            K3SizeCriterion::NetTurnover->value => K3Rules::int('k2_1_1c.net_turnover_gt_ore', $start, $end, $firstYear),
        ];
        $max = K3Rules::int('k2_1_1c.max_criteria_met', $start, $end, $firstYear);
        $outcomes = [];

        foreach (self::worlds($years, $thresholds) as $world) {
            $outcomes[] = count(self::metInEveryYear($world)) <= $max;
        }

        $outcomes = array_values(array_unique($outcomes));
        $names = self::names($years);
        $labels = self::joinWords(array_reverse(array_values($names)));

        if (count($outcomes) > 1) {
            return new K3K2Exception(null, __('Vad var medelantalet anställda under :years? Uppgiften avgör om undantaget i K2 punkt 1.1C gäller.', ['years' => $labels]));
        }

        $met = self::metCriteria($years, $thresholds);

        if ($outcomes[0]) {
            return new K3K2Exception(true, __('Undantaget i K2 punkt 1.1C gäller: högst ett av gränsvärdena har överskridits under vart och ett av räkenskapsåren :years.', ['years' => $labels]), $met);
        }

        return new K3K2Exception(false, __('Undantaget i K2 punkt 1.1C gäller inte: samma gränsvärden – :criteria – har överskridits under vart och ett av räkenskapsåren :years.', ['criteria' => self::criteriaWords($met), 'years' => $labels]), $met);
    }

    /**
     * The size test for a fiscal year kept in NONOX, from the figures of its
     * two preceding years and the company's legal form. See classify() for
     * $listed and $confirmedFirstYearStart.
     */
    public static function forFiscalYear(FiscalYear $fiscalYear, bool $listed = false, ?CarbonImmutable $confirmedFirstYearStart = null): K3SizeClassification
    {
        $company = Company::query()->findOrFail($fiscalYear->company_id);

        return self::classify(self::priorYears($fiscalYear), $fiscalYear->start_date, $listed, $confirmedFirstYearStart, $company->legal_form);
    }

    /** The K2 1.1C exception for a fiscal year kept in NONOX. See k2Exception() for $confirmedFirstYearStart. */
    public static function k2ExceptionForFiscalYear(FiscalYear $fiscalYear, ?CarbonImmutable $confirmedFirstYearStart = null): K3K2Exception
    {
        return self::k2Exception(self::priorYears($fiscalYear), $fiscalYear->start_date, $fiscalYear->end_date, $confirmedFirstYearStart);
    }

    /**
     * The figures of the fiscal years immediately before this one, newest
     * first, as far back as the test needs and the chain is unbroken. A year
     * kept in NONOX is read from the ledger (net turnover and balance sheet
     * total, AnnualAccounts) and its annual report (average employees, the
     * full-time equivalent when given); an earlier year from the figures a
     * person entered. At most two queries per year plus AnnualAccounts.
     *
     * @return list<K3SizeFigures>
     */
    public static function priorYears(FiscalYear $fiscalYear): array
    {
        return CurrentCompany::run($fiscalYear->company_id, function () use ($fiscalYear): array {
            $needed = K3Rules::int('larger_company.prior_years', $fiscalYear->start_date);
            $end = $fiscalYear->start_date->subDay();
            $years = [];

            for ($i = 0; $i < $needed; $i++) {
                $figures = self::ledgerFigures($end) ?? self::enteredFigures($end);

                if ($figures === null) {
                    break;
                }

                $years[] = $figures;
                $end = $figures->start->subDay();
            }

            return $years;
        });
    }

    /**
     * Hours worked turned into full-time equivalents, exactly (BFNAR
     * 2006:11 points 8–9): hours divided by the normal annual working time,
     * 1 920 hours adjusted for a year that is not twelve months.
     */
    public static function fteFromHours(int $hours, int $months = 12): BigRational
    {
        return BigRational::ofFraction(BigInteger::of($hours)->multipliedBy(12), BigInteger::of(K3Rules::int('normal_annual_hours'))->multipliedBy($months));
    }

    private static function ledgerFigures(CarbonImmutable $end): ?K3SizeFigures
    {
        $year = FiscalYear::query()->whereDate('end_date', $end->toDateString())->first();

        if (! $year instanceof FiscalYear) {
            return null;
        }

        $accounts = new AnnualAccounts($year, null);
        $report = AnnualReport::query()->where('fiscal_year_id', $year->id)->first();
        $employees = $report instanceof AnnualReport ? ($report->average_employees_fte ?? $report->average_employees) : null;

        return new K3SizeFigures($year->start_date, $year->end_date, $employees, $accounts->balanceSheet()['assets'], $accounts->netSales(), 'ledger');
    }

    private static function enteredFigures(CarbonImmutable $end): ?K3SizeFigures
    {
        $entered = PriorYearSizeFigure::query()->whereDate('end_date', $end->toDateString())->first();

        if (! $entered instanceof PriorYearSizeFigure) {
            return null;
        }

        return new K3SizeFigures($entered->start_date, $entered->end_date, $entered->average_employees_fte, $entered->balance_sheet_total_ore->ore, $entered->net_turnover_ore->ore, 'entered');
    }

    /** Whether the first missing year is the one before the confirmed first fiscal year. */
    private static function newlyStarted(CarbonImmutable $missingEnd, ?CarbonImmutable $confirmedFirstYearStart): bool
    {
        return $confirmedFirstYearStart !== null && $missingEnd->addDay()->isSameDay($confirmedFirstYearStart);
    }

    /**
     * The end date of the first preceding year that is missing or out of
     * order, or null when the first $needed years are the unbroken chain
     * before $start.
     *
     * @param  list<K3SizeFigures>  $years
     */
    private static function firstMissingYearEnd(array $years, CarbonImmutable $start, int $needed): ?CarbonImmutable
    {
        $end = $start->subDay();

        for ($i = 0; $i < $needed; $i++) {
            $figures = $years[$i] ?? null;

            if ($figures === null || ! $figures->end->isSameDay($end)) {
                return $end;
            }

            $end = $figures->start->subDay();
        }

        return null;
    }

    /**
     * Every combination of answers for the employee figures that are not
     * known: one world when all are known, up to four for two years. Each
     * world lists, per year, criterion => exceeded.
     *
     * @param  list<K3SizeFigures>  $years
     * @param  array<string, int>  $thresholds  by criterion value: whole employees, öre
     * @return list<list<array<string, bool>>>
     */
    private static function worlds(array $years, array $thresholds): array
    {
        $worlds = [[]];

        foreach ($years as $figures) {
            $employees = $figures->averageEmployeesRounded();
            $options = $employees === null ? [true, false] : [$employees > $thresholds[K3SizeCriterion::Employees->value]];
            $next = [];

            foreach ($worlds as $world) {
                foreach ($options as $exceedsEmployees) {
                    $next[] = [...$world, [
                        K3SizeCriterion::Employees->value => $exceedsEmployees,
                        K3SizeCriterion::BalanceSheetTotal->value => $figures->balanceSheetTotalOre > $thresholds[K3SizeCriterion::BalanceSheetTotal->value],
                        K3SizeCriterion::NetTurnover->value => $figures->exceedsNetTurnover($thresholds[K3SizeCriterion::NetTurnover->value]),
                    ]];
                }
            }

            $worlds = $next;
        }

        return $worlds;
    }

    /**
     * The criteria exceeded in every year of a world.
     *
     * @param  list<array<string, bool>>  $world
     * @return list<string>
     */
    private static function metInEveryYear(array $world): array
    {
        return array_values(array_filter(
            array_column(K3SizeCriterion::cases(), 'value'),
            fn (string $criterion): bool => collect($world)->every(fn (array $year): bool => $year[$criterion]),
        ));
    }

    /**
     * The criteria known to be exceeded in every measured year.
     *
     * @param  list<K3SizeFigures>  $years
     * @param  array<string, int>  $thresholds
     * @return list<K3SizeCriterion>
     */
    private static function metCriteria(array $years, array $thresholds): array
    {
        return array_values(array_filter(
            K3SizeCriterion::cases(),
            fn (K3SizeCriterion $criterion): bool => collect($years)->every(fn (K3SizeFigures $figures): bool => in_array($criterion, self::knownExceeded($figures, $thresholds), true)),
        ));
    }

    /**
     * The criteria a year is known to exceed, in the order of the law.
     *
     * @param  array<string, int>  $thresholds
     * @return list<K3SizeCriterion>
     */
    private static function knownExceeded(K3SizeFigures $figures, array $thresholds): array
    {
        $employees = $figures->averageEmployeesRounded();
        $exceeded = [
            K3SizeCriterion::Employees->value => $employees !== null && $employees > $thresholds[K3SizeCriterion::Employees->value],
            K3SizeCriterion::BalanceSheetTotal->value => $figures->balanceSheetTotalOre > $thresholds[K3SizeCriterion::BalanceSheetTotal->value],
            K3SizeCriterion::NetTurnover->value => $figures->exceedsNetTurnover($thresholds[K3SizeCriterion::NetTurnover->value]),
        ];

        return array_values(array_filter(K3SizeCriterion::cases(), fn (K3SizeCriterion $criterion): bool => $exceeded[$criterion->value]));
    }

    /**
     * A display name per year, keyed by end date: the label ("2025"), or the
     * dates of every year when two labels would be the same.
     *
     * @param  list<K3SizeFigures>  $years
     * @return array<string, string>
     */
    private static function names(array $years): array
    {
        $labels = array_map(fn (K3SizeFigures $figures): string => $figures->label(), $years);
        $ambiguous = count($labels) !== count(array_unique($labels));
        $names = [];

        foreach ($years as $figures) {
            $names[$figures->key()] = $ambiguous ? $figures->period() : $figures->label();
        }

        return $names;
    }

    /**
     * "2024: 30,00 (avrundat 30); 2025: 50,50 (avrundat 51)", oldest first.
     *
     * @param  list<K3SizeFigures>  $years
     * @param  array<string, string>  $names
     */
    private static function employeesText(array $years, array $names): string
    {
        $parts = [];

        foreach (array_reverse($years) as $figures) {
            $parts[] = $figures->averageEmployees === null
                ? __(':year: uppgift saknas', ['year' => $names[$figures->key()]])
                : __(':year: :exact (avrundat :rounded)', ['year' => $names[$figures->key()], 'exact' => $figures->averageEmployeesText(), 'rounded' => $figures->averageEmployeesRounded()]);
        }

        return implode('; ', $parts);
    }

    /** @param list<K3SizeCriterion> $criteria */
    private static function criteriaWords(array $criteria): string
    {
        return $criteria === [] ? __('inga gränsvärden') : self::joinWords(array_map(fn (K3SizeCriterion $criterion): string => $criterion->label(), $criteria));
    }

    /** @param list<string> $words "a", "a och b", "a, b och c" */
    private static function joinWords(array $words): string
    {
        if (count($words) < 2) {
            return implode('', $words);
        }

        $last = array_pop($words);

        return implode(', ', $words).' '.__('och').' '.$last;
    }
}
