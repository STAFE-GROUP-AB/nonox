<?php

declare(strict_types=1);

namespace App\Domain\Deadlines\Providers;

use App\Domain\Company\LegalForm;
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

        $large = $company->isVatRegistered() && $company->turnover_over_40_msek;
        $deadlines = [];

        foreach (self::taxYears($company, $from->subYear(), $to) as [$start, $end]) {
            for ($month = $start->addMonthNoOverflow()->startOfMonth(); $month->lessThanOrEqualTo($end->addMonthNoOverflow()); $month = $month->addMonthNoOverflow()) {
                $label = $month->translatedFormat('F Y');

                $deadlines[] = new Deadline(
                    'preliminary_tax:'.$month->format('Y-m'),
                    DeadlineType::PreliminaryTax,
                    __('Betala preliminärskatt för :month', ['month' => $label]),
                    Workdays::onDay($month, 12, $large ? [1 => 17] : [1 => 17, 8 => 17]),
                    $label,
                    __('Företaget har F-skatt. Beloppet och den första månaden står i ditt beslut om preliminärskatt från Skatteverket.'),
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
                __('Fyllnadsinbetalning för :year?', ['year' => self::yearLabel($start, $end)]),
                Workdays::onDay($topUp, 12),
                self::yearLabel($start, $end),
                __('Blir skatten för året högre än preliminärskatten kan du betala in skillnaden senast den här dagen, innan kostnadsränta börjar räknas.'),
                null,
                self::ARTICLE,
                self::SOURCE,
                isRecommendation: true,
            );
        }

        return $deadlines;
    }

    /**
     * The beskattningsår that overlap the span: the fiscal years, for a
     * sole trader the calendar years.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private static function taxYears(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($company->legal_form === LegalForm::Ef) {
            return array_map(fn (int $year): array => [$from->setDate($year, 1, 1)->startOfDay(), $from->setDate($year, 12, 31)->startOfDay()], range($from->year, $to->year));
        }

        return array_map(fn ($year): array => [$year->start_date, $year->end_date], FiscalCalendar::years($company, $from, $to));
    }

    public static function yearLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->year === $end->year ? (string) $start->year : "{$start->year}/{$end->year}";
    }
}
