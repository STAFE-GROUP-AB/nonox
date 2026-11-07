<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

use App\Models\AnnualReport;
use App\Models\FiscalYear;
use App\Models\PriorYearSizeFigure;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * The size test (ADR-059, addendum 2026-11-07, S1-P2): is the company a
 * större or a mindre företag under ÅRL 1 kap. 3 § första stycket 4–5
 * (S1-R9), measured as BFNAR 2006:11 says (S1-R9.1, R9.2), and does the
 * first paragraph of K2 1.1C lift K2 1.1B (S1-R1.3)?
 *
 * The measured years are the two that come immediately before the reporting
 * year (GV point 6). Net turnover is adjusted to twelve months, the balance
 * sheet total is not (GV point 5). Every threshold is strict ("mer än").
 * The thresholds are data (K3Rules).
 *
 * NONOX never guesses. The answer is undetermined when the two readings of
 * "mer än ett" disagree (the same two criteria in both years, or any two in
 * each year; open question H8), when fewer than two preceding years are
 * known for a company that is not listed (S1-R9.1 open), or when a missing
 * employee figure would change the answer.
 */
final class K3CompanySize
{
    /**
     * Classifies the company for a reporting year that begins on
     * $reportingStart, from the figures of the preceding years, newest first.
     *
     * @param  list<K3SizeFigures>  $years
     */
    public static function classify(array $years, CarbonImmutable $reportingStart, bool $listed = false): K3SizeClassification
    {
        if ($listed) {
            return new K3SizeClassification(K3SizeClass::Storre, __('Större företag: företagets värdepapper är upptagna till handel på en reglerad marknad (ÅRL 1 kap. 3 § första stycket 4).'), 'S1-R9', K3SizeClass::Storre, K3SizeClass::Storre);
        }

        $needed = K3Rules::int('larger_company.prior_years', $reportingStart);
        $missing = self::firstMissingYearEnd($years, $reportingStart, $needed);

        if ($missing !== null) {
            return new K3SizeClassification(K3SizeClass::Undetermined, __('Hur stort var företaget under räkenskapsåret som slutade :date? Storleken bedöms på de två räkenskapsår som närmast föregår årsredovisningens år, och för det året saknas uppgifter. Är företaget nystartat avgör en människa hur det ska bedömas – lagen säger inte hur, och NONOX gissar inte.', ['date' => $missing->toDateString()]), 'S1-R9.1');
        }

        $years = array_slice($years, 0, $needed);
        $thresholds = [
            K3SizeCriterion::Employees->value => K3Rules::int('larger_company.employees_gt', $reportingStart) * 100,
            K3SizeCriterion::BalanceSheetTotal->value => K3Rules::int('larger_company.balance_sheet_total_gt_ore', $reportingStart),
            K3SizeCriterion::NetTurnover->value => K3Rules::int('larger_company.net_turnover_gt_ore', $reportingStart),
        ];
        $criteriaNeeded = K3Rules::int('larger_company.criteria_needed', $reportingStart);
        $same = [];
        $any = [];

        foreach (self::worlds($years, $thresholds) as $world) {
            $same[] = count(self::metInEveryYear($world)) >= $criteriaNeeded ? K3SizeClass::Storre : K3SizeClass::Mindre;
            $any[] = collect($world)->every(fn (array $year): bool => count(array_filter($year)) >= $criteriaNeeded) ? K3SizeClass::Storre : K3SizeClass::Mindre;
        }

        $same = array_values(array_unique(array_map(fn (K3SizeClass $class): string => $class->value, $same)));
        $any = array_values(array_unique(array_map(fn (K3SizeClass $class): string => $class->value, $any)));
        $sameTwo = count($same) === 1 ? K3SizeClass::from($same[0]) : K3SizeClass::Undetermined;
        $anyTwo = count($any) === 1 ? K3SizeClass::from($any[0]) : K3SizeClass::Undetermined;
        $exceeded = [];

        foreach ($years as $figures) {
            $exceeded[$figures->label()] = self::knownExceeded($figures, $thresholds);
        }

        $labels = self::yearLabels($years);

        if ($sameTwo === K3SizeClass::Undetermined || $anyTwo === K3SizeClass::Undetermined) {
            $unknown = array_map(fn (K3SizeFigures $figures): string => $figures->label(), array_values(array_filter($years, fn (K3SizeFigures $figures): bool => $figures->employeesFteHundredths === null)));

            return new K3SizeClassification(K3SizeClass::Undetermined, __('Vad var medelantalet anställda under :years? Uppgiften avgör om företaget är ett större företag.', ['years' => self::joinWords(array_reverse($unknown))]), 'S1-R9.2', $sameTwo, $anyTwo, $exceeded, $years);
        }

        if ($sameTwo !== $anyTwo) {
            $details = implode('; ', array_map(fn (string $label, array $criteria): string => $label.': '.self::criteriaWords($criteria), array_keys($exceeded), $exceeded));

            return new K3SizeClassification(K3SizeClass::Undetermined, __('Är företaget ett större företag? Det har överskridit två gränsvärden båda åren, men inte samma två gränsvärden (:details). Lagen säger inte om det måste vara samma två gränsvärden båda åren, så NONOX gissar inte.', ['details' => $details]), 'S1-R9', $sameTwo, $anyTwo, $exceeded, $years);
        }

        if ($sameTwo === K3SizeClass::Storre) {
            $met = array_values(array_intersect(...array_values(array_map(fn (array $criteria): array => array_map(fn (K3SizeCriterion $criterion): string => $criterion->value, $criteria), $exceeded))));

            return new K3SizeClassification(K3SizeClass::Storre, __('Större företag: :criteria har överskridit gränsvärdena under vart och ett av räkenskapsåren :years (ÅRL 1 kap. 3 § första stycket 4).', ['criteria' => ucfirst(self::criteriaWords(array_map(fn (string $value): K3SizeCriterion => K3SizeCriterion::from($value), $met))), 'years' => $labels]), 'S1-R9', $sameTwo, $anyTwo, $exceeded, $years);
        }

        return new K3SizeClassification(K3SizeClass::Mindre, __('Mindre företag: högst ett av gränsvärdena har överskridits under vart och ett av räkenskapsåren :years (ÅRL 1 kap. 3 § första stycket 5).', ['years' => $labels]), 'S1-R9', $sameTwo, $anyTwo, $exceeded, $years);
    }

    /**
     * The first paragraph of K2 1.1C (S1-R1.3) for a fiscal year from $start
     * to $end: the exception applies when at most one criterion is exceeded
     * in each of the two preceding years. BFN's comment: exceeding the same
     * two thresholds in both years removes it.
     *
     * @param  list<K3SizeFigures>  $years  newest first
     */
    public static function k2Exception(array $years, CarbonImmutable $start, CarbonImmutable $end, bool $firstYear = false): K3K2Exception
    {
        $employees = K3Rules::entry('k2_1_1c.employees_gt', $start, $end, $firstYear);

        if ($employees === null) {
            return new K3K2Exception(null, __('NONOX har K2 punkt 1.1C i den lydelse som gäller räkenskapsår som börjar efter den 31 december 2025. För ett tidigare räkenskapsår bedömer en människa undantaget.'));
        }

        // BFN's comment to K2 1.1C: the two years that come immediately before, as BFNAR 2006:11 point 6.
        $needed = K3Rules::int('larger_company.prior_years', $start);
        $missing = self::firstMissingYearEnd($years, $start, $needed);

        if ($missing !== null) {
            return new K3K2Exception(null, __('För räkenskapsåret som slutade :date saknas uppgifter, och undantaget i K2 punkt 1.1C bedöms på de två närmast föregående räkenskapsåren. Ett nystartat företag får enligt BFN:s kommentar tillämpa K2 de två första räkenskapsåren om punkt 1.1A inte hindrar det – det bekräftar en människa.', ['date' => $missing->toDateString()]));
        }

        $years = array_slice($years, 0, $needed);
        $thresholds = [
            K3SizeCriterion::Employees->value => K3Rules::int('k2_1_1c.employees_gt', $start, $end, $firstYear) * 100,
            K3SizeCriterion::BalanceSheetTotal->value => K3Rules::int('k2_1_1c.balance_sheet_total_gt_ore', $start, $end, $firstYear),
            K3SizeCriterion::NetTurnover->value => K3Rules::int('k2_1_1c.net_turnover_gt_ore', $start, $end, $firstYear),
        ];
        $max = K3Rules::int('k2_1_1c.max_criteria_met', $start, $end, $firstYear);
        $outcomes = [];

        foreach (self::worlds($years, $thresholds) as $world) {
            $outcomes[] = count(self::metInEveryYear($world)) <= $max;
        }

        $outcomes = array_values(array_unique($outcomes));
        $labels = self::yearLabels($years);

        if (count($outcomes) > 1) {
            return new K3K2Exception(null, __('Vad var medelantalet anställda under :years? Uppgiften avgör om undantaget i K2 punkt 1.1C gäller.', ['years' => $labels]));
        }

        $known = array_map(fn (K3SizeFigures $figures): array => array_map(fn (K3SizeCriterion $criterion): string => $criterion->value, self::knownExceeded($figures, $thresholds)), $years);
        $met = array_map(fn (string $value): K3SizeCriterion => K3SizeCriterion::from($value), array_values(array_intersect(...$known)));

        if ($outcomes[0]) {
            return new K3K2Exception(true, __('Undantaget i K2 punkt 1.1C gäller: högst ett av gränsvärdena har överskridits under vart och ett av räkenskapsåren :years.', ['years' => $labels]), $met);
        }

        return new K3K2Exception(false, __('Undantaget i K2 punkt 1.1C gäller inte: :criteria har överskridit gränsvärdena under vart och ett av räkenskapsåren :years.', ['criteria' => ucfirst(self::criteriaWords($met)), 'years' => $labels]), $met);
    }

    /** The size test for a fiscal year kept in NONOX, from the figures of its two preceding years. */
    public static function forFiscalYear(FiscalYear $fiscalYear, bool $listed = false): K3SizeClassification
    {
        return self::classify(self::priorYears($fiscalYear), $fiscalYear->start_date, $listed);
    }

    /**
     * The K2 1.1C exception for a fiscal year kept in NONOX. The year counts
     * as the company's first when nothing is known about an earlier year.
     */
    public static function k2ExceptionForFiscalYear(FiscalYear $fiscalYear): K3K2Exception
    {
        $years = self::priorYears($fiscalYear);

        return self::k2Exception($years, $fiscalYear->start_date, $fiscalYear->end_date, firstYear: $years === []);
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
     * Hours worked turned into full-time equivalents (BFNAR 2006:11 points
     * 8–9): hours divided by the normal annual working time, 1 920 hours
     * adjusted for a year that is not twelve months. In hundredths, rounded
     * up, so that a figure just above a whole-number threshold stays above
     * it ("mer än 3" holds for 3,001 and for 3,01).
     */
    public static function fteFromHours(int $hours, int $months = 12): int
    {
        $normal = K3Rules::int('normal_annual_hours');

        return BigDecimal::of($hours)
            ->multipliedBy(12 * 100)
            ->dividedBy($normal * $months, 0, RoundingMode::Ceiling)
            ->toInt();
    }

    private static function ledgerFigures(CarbonImmutable $end): ?K3SizeFigures
    {
        $year = FiscalYear::query()->whereDate('end_date', $end->toDateString())->first();

        if (! $year instanceof FiscalYear) {
            return null;
        }

        $accounts = new AnnualAccounts($year, null);
        $report = AnnualReport::query()->where('fiscal_year_id', $year->id)->first();
        $employees = null;

        if ($report instanceof AnnualReport) {
            $employees = $report->average_employees_fte !== null
                ? BigDecimal::of($report->average_employees_fte)->multipliedBy(100)->toScale(0, RoundingMode::Up)->toInt()
                : $report->average_employees * 100;
        }

        return new K3SizeFigures($year->start_date, $year->end_date, $employees, $accounts->balanceSheet()['assets'], $accounts->netSales(), 'ledger');
    }

    private static function enteredFigures(CarbonImmutable $end): ?K3SizeFigures
    {
        $entered = PriorYearSizeFigure::query()->whereDate('end_date', $end->toDateString())->first();

        if (! $entered instanceof PriorYearSizeFigure) {
            return null;
        }

        return new K3SizeFigures($entered->start_date, $entered->end_date, $entered->employeesFteHundredths(), $entered->balance_sheet_total_ore->ore, $entered->net_turnover_ore->ore, 'entered');
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
     * @param  array<string, int>  $thresholds  by criterion value
     * @return list<list<array<string, bool>>>
     */
    private static function worlds(array $years, array $thresholds): array
    {
        $worlds = [[]];

        foreach ($years as $figures) {
            $options = $figures->employeesFteHundredths === null
                ? [true, false]
                : [$figures->employeesFteHundredths > $thresholds[K3SizeCriterion::Employees->value]];
            $next = [];

            foreach ($worlds as $world) {
                foreach ($options as $employees) {
                    $next[] = [...$world, [
                        K3SizeCriterion::Employees->value => $employees,
                        K3SizeCriterion::BalanceSheetTotal->value => $figures->balanceSheetTotalOre > $thresholds[K3SizeCriterion::BalanceSheetTotal->value],
                        K3SizeCriterion::NetTurnover->value => $figures->annualisedNetTurnoverOre() > $thresholds[K3SizeCriterion::NetTurnover->value],
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
     * The criteria a year is known to exceed, in the order of the law.
     *
     * @param  array<string, int>  $thresholds
     * @return list<K3SizeCriterion>
     */
    private static function knownExceeded(K3SizeFigures $figures, array $thresholds): array
    {
        $values = [
            K3SizeCriterion::Employees->value => $figures->employeesFteHundredths,
            K3SizeCriterion::BalanceSheetTotal->value => $figures->balanceSheetTotalOre,
            K3SizeCriterion::NetTurnover->value => $figures->annualisedNetTurnoverOre(),
        ];

        return array_values(array_filter(
            K3SizeCriterion::cases(),
            fn (K3SizeCriterion $criterion): bool => $values[$criterion->value] !== null && $values[$criterion->value] > $thresholds[$criterion->value],
        ));
    }

    /** @param list<K3SizeFigures> $years newest first; written oldest first */
    private static function yearLabels(array $years): string
    {
        return self::joinWords(array_reverse(array_map(fn (K3SizeFigures $figures): string => $figures->label(), $years)));
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
