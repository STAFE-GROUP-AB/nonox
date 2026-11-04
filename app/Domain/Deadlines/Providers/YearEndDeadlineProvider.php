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
 * The dates after a fiscal year ends (REGLER.md §4–6 and §9):
 *
 * - Inkomstdeklaration: AB and ekonomisk förening by the year-end group of
 *   SFL 32 kap. 2 § (electronic, the later date), handelsbolag and
 *   kommanditbolag on the same dates (flagged, §4.2), a sole trader 2 May
 *   the year after (32 kap. 1 §); moved to a working day.
 * - Årsstämma (AB, ekonomisk förening): within six months (ABL 7 kap. 10 §,
 *   EFL 6 kap. 9 §), not moved.
 * - Årsredovisning to Bolagsverket (AB, ekonomisk förening): the
 *   seven-month limit before förseningsavgift (ÅRL 8 kap. 6 §), not moved,
 *   always flagged (§0.1, §0.2).
 * - Bokslut, a recommendation: three months after the year for AB and
 *   ekonomisk förening, otherwise one month before the income tax return.
 *
 * Ideell förening and stiftelse get no date: NONOX cannot tell whether they
 * must file. One query.
 */
final class YearEndDeadlineProvider implements DeadlineProvider
{
    public const string INCOME_TAX_AB = 'https://www.skatteverket.se/foretag/inkomstdeklaration/deklareraatettaktiebolagellerekonomiskforening.4.46ae6b26141980f1e2d1261.html';

    public const string INCOME_TAX_HB = 'https://www.skatteverket.se/foretag/inkomstdeklaration/deklareraatetthandelsbolag.4.309a41aa1672ad0c837988b.html';

    public const string INCOME_TAX_EF = 'https://www.skatteverket.se/foretag/inkomstdeklaration/deklareraenskildnaringsverksamhet.4.133ff59513d6f9ee2ebf00.html';

    public const string ANNUAL_REPORT = 'https://bolagsverket.se/foretag/aktiebolag/arsredovisningforaktiebolag/arsredovisningsguidenforaktiebolag.5550.html';

    public const string LAW_ABL = 'https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/aktiebolagslag-2005551_sfs-2005-551/';

    private const string ARTICLE_TAX = 'viktiga-datum/inkomstdeklarationen';

    private const string ARTICLE_MEETING = 'viktiga-datum/arsstamma-och-arsredovisning';

    /** The year ends SFL 32 kap. 2 § names; the month ranges around them are Skatteverket's. */
    private const array NAMED_YEAR_ENDS = ['04-30', '06-30', '08-31', '12-31'];

    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $form = $company->legal_form;

        if (in_array($form, [LegalForm::IdeellForening, LegalForm::Stiftelse], true)) {
            return [];
        }

        $deadlines = [];

        // A year that ended up to 16 months ago can still have dates ahead (income tax for a year ending in January).
        foreach (FiscalCalendar::years($company, $from->subMonthsNoOverflow(16), $to) as $year) {
            $end = $year->end_date;

            if ($end->greaterThanOrEqualTo($to)) {
                continue;
            }

            $label = PreliminaryTaxProvider::yearLabel($year->start_date, $end);
            $hasMeeting = in_array($form, [LegalForm::Ab, LegalForm::EkonomiskForening], true);
            $tax = $this->incomeTax($form, $end, $label);
            $deadlines[] = $tax;

            if ($hasMeeting) {
                $meeting = $end->addMonthsNoOverflow(6);
                $deadlines[] = new Deadline(
                    'annual_meeting:'.$end->toDateString(),
                    DeadlineType::AnnualMeeting,
                    $form === LegalForm::Ab ? __('Årsstämma för :year', ['year' => $label]) : __('Föreningsstämma för :year', ['year' => $label]),
                    $meeting,
                    $label,
                    __('Stämman ska hållas inom sex månader efter räkenskapsårets slut. Den fastställer årsredovisningen och beslutar om vinsten.'),
                    'accounting.annualReport',
                    self::ARTICLE_MEETING,
                    self::LAW_ABL,
                    legalVerify: self::monthCountUnclear($end, $meeting),
                );
                $deadlines[] = new Deadline(
                    'annual_report:'.$end->toDateString(),
                    DeadlineType::AnnualReport,
                    __('Årsredovisningen för :year ska vara hos Bolagsverket', ['year' => $label]),
                    $end->addMonthsNoOverflow(7),
                    $label,
                    __('Sista dagen innan Bolagsverket tar ut förseningsavgift. Skicka den inom en månad efter stämman.'),
                    'accounting.annualReport',
                    self::ARTICLE_MEETING,
                    self::ANNUAL_REPORT,
                    legalVerify: true,
                );
            }

            $closing = $hasMeeting ? $end->addMonthsNoOverflow(3) : $tax->date->subMonthNoOverflow();
            $deadlines[] = new Deadline(
                'closing:'.$end->toDateString(),
                DeadlineType::Closing,
                __('Gör bokslutet för :year', ['year' => $label]),
                $closing,
                $label,
                $hasMeeting
                    ? __('Lagen har inget datum för bokslutet, men stämman, revisorn och deklarationen behöver det i god tid.')
                    : __('Lagen har inget datum för bokslutet, men deklarationen bygger på det.'),
                'accounting.closing',
                'ansvar-och-regler/arsredovisning-och-bokslut',
                isRecommendation: true,
            );
        }

        return $deadlines;
    }

    private function incomeTax(LegalForm $form, CarbonImmutable $end, string $label): Deadline
    {
        if ($form === LegalForm::Ef) {
            return new Deadline(
                'income_tax_return:'.$end->toDateString(),
                DeadlineType::IncomeTaxReturn,
                __('Inkomstdeklaration med NE-bilaga för :year', ['year' => $label]),
                Workdays::next($end->setDate($end->year + 1, 5, 2)->startOfDay()),
                $label,
                __('Du som driver enskild firma deklarerar näringsverksamheten på NE-bilagan i din egen deklaration.'),
                'accounting.incomeTax',
                self::ARTICLE_TAX,
                self::INCOME_TAX_EF,
            );
        }

        $partnership = in_array($form, [LegalForm::Hb, LegalForm::Kb], true);

        return new Deadline(
            'income_tax_return:'.$end->toDateString(),
            DeadlineType::IncomeTaxReturn,
            $partnership ? __('Inkomstdeklaration 4 för :year', ['year' => $label]) : __('Inkomstdeklaration 2 för :year', ['year' => $label]),
            Workdays::next(self::legalPersonDate($end)),
            $label,
            $partnership
                ? __('Handelsbolaget deklarerar efter räkenskapsårets slut, och delägarna deklarerar sin del själva.')
                : __('Datumet beror på när räkenskapsåret slutar. Det gäller när deklarationen lämnas elektroniskt; på papper är det en månad tidigare.'),
            'accounting.incomeTax',
            self::ARTICLE_TAX,
            $partnership ? self::INCOME_TAX_HB : self::INCOME_TAX_AB,
            legalVerify: $partnership || ! in_array($end->format('m-d'), self::NAMED_YEAR_ENDS, true),
        );
    }

    /** SFL 32 kap. 2 §, electronic (the second stycke's month later), by the month the year ends. */
    public static function legalPersonDate(CarbonImmutable $end): CarbonImmutable
    {
        return match (true) {
            $end->month <= 4 => $end->setDate($end->year, 12, 1)->startOfDay(),
            $end->month <= 6 => $end->setDate($end->year + 1, 1, 15)->startOfDay(),
            $end->month <= 8 => $end->setDate($end->year + 1, 4, 1)->startOfDay(),
            default => $end->setDate($end->year + 1, 8, 1)->startOfDay(),
        };
    }

    /**
     * Lag (1930:173) 1 § counts to the same day number; for a year ending on
     * the last day of a shorter month the last day of the target month may
     * be meant instead (REGLER.md §0.2). Flag those.
     */
    private static function monthCountUnclear(CarbonImmutable $end, CarbonImmutable $limit): bool
    {
        return $end->isLastOfMonth() && $limit->day < $limit->daysInMonth;
    }
}
