<?php

declare(strict_types=1);

namespace App\Domain\Deadlines\Providers;

use App\Domain\Company\LegalForm;
use App\Domain\Deadlines\AgiDates;
use App\Domain\Deadlines\Contracts\DeadlineProvider;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\FiscalCalendar;
use App\Domain\Deadlines\Workdays;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Debiterad preliminärskatt for a company with F-skatt (REGLER.md §3): one
 * payment a month from the second month of the beskattningsår to the month
 * after it ends (SFL 62 kap. 4 §), on the 12th, the 17th in January and
 * August; the 12th in August too when the VAT base is above 40 million
 * kronor. A sole trader's beskattningsår is the calendar year. A trading or
 * limited partnership pays no income tax itself, so it gets none.
 *
 * NONOX cannot know whether there is a decision, its amount or a later
 * first month (62 kap. 5–7 §§), so every date carries legalVerify and says
 * to follow the decision. Also, as a recommendation (product advice, §3.4),
 * a reminder of a top-up payment before kostnadsränta starts on the 13th of
 * the second month after the year (65 kap. 4 §). One query.
 */
final class PreliminaryTaxProvider implements DeadlineProvider
{
    public const string SOURCE = 'https://www.skatteverket.se/foretag/skatterochavdrag/skattekontobetalaochfatillbaka/preliminarskattforforetagochforeningar.4.233f91f71260075abe8800010616.html';

    private const string ARTICLE = 'viktiga-datum/preliminarskatt';

    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $company->f_skatt || in_array($company->legal_form, [LegalForm::Hb, LegalForm::Kb], true)) {
            return [];
        }

        $large = AgiDates::isLarge($company);
        $deadlines = [];

        foreach (self::taxYears($company, $from->subYear(), $to) as [$start, $end]) {
            for ($month = $start->addMonthNoOverflow()->startOfMonth(); $month->lessThanOrEqualTo($end->addMonthNoOverflow()); $month = $month->addMonthNoOverflow()) {
                $label = $month->translatedFormat('F Y');

                $deadlines[] = new Deadline(
                    'preliminary_tax:'.$month->format('Y-m'),
                    DeadlineType::PreliminaryTax,
                    __('Betala F-skatt (preliminärskatt), :month', ['month' => $label]),
                    Workdays::onDay($month, 12, $large ? [1 => 17] : [1 => 17, 8 => 17]),
                    $label,
                    __('Debiterad preliminärskatt enligt ert beslut från Skatteverket, en del av årets inkomstskatt i förskott. Beloppet och den första månaden står i beslutet; står det inget belopp att betala gäller datumet inte er.'),
                    null,
                    self::ARTICLE,
                    self::SOURCE,
                    legalVerify: true,
                );
            }

            $topUp = $end->startOfMonth()->addMonthsNoOverflow(2);
            $deadlines[] = new Deadline(
                'preliminary_tax_top_up:'.$end->toDateString(),
                DeadlineType::PreliminaryTax,
                __('Överväg en fyllnadsinbetalning för :year', ['year' => self::yearLabel($start, $end)]),
                self::topUpDay($topUp->setDay(12)),
                self::yearLabel($start, $end),
                __('Väntar ni kvarskatt för året kan en extra inbetalning som är bokförd på skattekontot senast den här dagen undvika kostnadsränta på det som överstiger 30 000 kronor. Upp till 30 000 kronor börjar räntan först den 4 i femte månaden efter beskattningsåret.'),
                null,
                self::ARTICLE,
                self::SOURCE,
                isRecommendation: true,
            );
        }

        return $deadlines;
    }

    /**
     * The beskattningsår that overlap the span: for a sole trader the
     * calendar years; for a legal person the fiscal years, where one that
     * ends on another day than 31 December, 30 April, 30 June or 31 August
     * counts as the twelve months ending on the next of them (SFL 3 kap. 5 §,
     * REGLER.md §3.2). Two fiscal years mapped to the same year count once.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function taxYears(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($company->legal_form === LegalForm::Ef) {
            return array_map(fn (int $year): array => [$from->setDate($year, 1, 1)->startOfDay(), $from->setDate($year, 12, 31)->startOfDay()], range($from->year, $to->year));
        }

        $years = [];

        foreach (FiscalCalendar::years($company, $from, $to) as $year) {
            $end = self::namedYearEnd($year->end_date);
            $years[$end->toDateString()] = [$end->subYear()->addDay(), $end];
        }

        return array_values($years);
    }

    /** The first of 30 April, 30 June, 31 August and 31 December on or after the day (SFL 3 kap. 5 §). */
    public static function namedYearEnd(CarbonImmutable $end): CarbonImmutable
    {
        foreach (['04-30', '06-30', '08-31', '12-31'] as $monthDay) {
            $candidate = CarbonImmutable::parse("{$end->year}-{$monthDay}");

            if ($candidate->greaterThanOrEqualTo($end->startOfDay())) {
                return $candidate;
            }
        }

        return CarbonImmutable::parse(($end->year + 1).'-04-30');
    }

    /**
     * The day to recommend for a top-up before kostnadsränta starts on the
     * 13th (SFL 65 kap. 4–6 §§): the 12th; when the 12th is a Saturday or
     * Sunday, interest starts the following Tuesday (65 kap. 18 §), so the
     * Monday, unless the Monday is a holiday; when the 12th is a weekday
     * holiday, or the Monday after a weekend 12th is one, the working day
     * before the 12th (REGLER.md §3.4).
     */
    public static function topUpDay(CarbonImmutable $twelfth): CarbonImmutable
    {
        if ($twelfth->isWeekend()) {
            $monday = $twelfth->next(CarbonImmutable::MONDAY);

            return Workdays::isWorkday($monday) ? $monday : Workdays::before($twelfth);
        }

        return Workdays::isWorkday($twelfth) ? $twelfth : Workdays::before($twelfth);
    }

    public static function yearLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->year === $end->year ? (string) $start->year : "{$start->year}/{$end->year}";
    }
}
