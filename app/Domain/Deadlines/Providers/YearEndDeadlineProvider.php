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
 *   SFL 32 kap. 2 § (electronic, the later date; a year ending on another
 *   day counts as ending on the next named one, SFL 3 kap. 5 §), handelsbolag
 *   and kommanditbolag on the same dates for their särskilda uppgifter (33
 *   kap. 6 och 10 §§), a sole trader 2 May the year after (32 kap. 1 §);
 *   moved to a working day.
 * - Årsstämma (AB, ekonomisk förening): within six months (ABL 7 kap. 10 §,
 *   EFL 6 kap. 9 §), counted to the same day number (lag (1930:173) 1 §),
 *   not moved.
 * - Årsredovisning to Bolagsverket (AB, ekonomisk förening): the
 *   seven-month limit before förseningsavgift (ÅRL 8 kap. 6 §), not moved,
 *   always flagged (§0.1, §0.2).
 * - Bokslut, a recommendation: three months after the year for AB and
 *   ekonomisk förening, otherwise one month before the income tax return.
 *
 * Ideell förening and stiftelse get no date: whether they must file depends
 * on their income (SFL 30 kap. 4 §), which NONOX does not ask. One query.
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
                    $form === LegalForm::Ab ? __('Håll årsstämman för :year', ['year' => $label]) : __('Håll föreningsstämman för :year', ['year' => $label]),
                    $meeting,
                    $label,
                    __('Stämman ska hållas inom sex månader efter räkenskapsårets slut. Den fastställer årsredovisningen och beslutar om vinsten.'),
                    'accounting.annualReport',
                    self::ARTICLE_MEETING,
                    self::LAW_ABL,
                );
                $deadlines[] = new Deadline(
                    'annual_report:'.$end->toDateString(),
                    DeadlineType::AnnualReport,
                    __('Skicka årsredovisningen för :year till Bolagsverket', ['year' => $label]),
                    $end->addMonthsNoOverflow(7),
                    $label,
                    __('Sista dagen innan Bolagsverket tar ut förseningsavgift. Skicka den inom en månad efter stämman.'),
                    'accounting.annualReport',
                    self::ARTICLE_MEETING,
                    self::ANNUAL_REPORT,
                    legalVerify: true,
                );
            }

            if (in_array($form, [LegalForm::Hb, LegalForm::Kb], true)) {
                // NONOX does not know the partners: a question, not a date (ÅRL 8 kap. 3 § 3, REGLER.md §6).
                $deadlines[] = new Deadline(
                    'annual_report:'.$end->toDateString(),
                    DeadlineType::AnnualReport,
                    __('Kontrollera om handelsbolaget ska skicka in årsredovisningen'),
                    self::workdayOrBefore($end->addMonthsNoOverflow(6)),
                    $label,
                    __('Har handelsbolaget en juridisk person som delägare ska årsredovisningen vara hos Bolagsverket senast sex månader efter räkenskapsårets slut (årsredovisningslagen 8 kap. 3 §). Har bolaget bara fysiska personer som delägare gäller det inte.'),
                    'accounting.annualReport',
                    self::ARTICLE_MEETING,
                    self::ANNUAL_REPORT,
                    isRecommendation: true,
                    legalVerify: true,
                );
            }

            // A recommendation is never on a weekend or holiday: the working day before.
            $closing = self::workdayOrBefore($hasMeeting ? $end->addMonthsNoOverflow(3) : $tax->date->subMonthNoOverflow());
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
                __('Lämna inkomstdeklarationen med NE-bilaga för :year', ['year' => $label]),
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
            $partnership ? __('Lämna inkomstdeklaration 4 för :year', ['year' => $label]) : __('Lämna inkomstdeklaration 2 för :year', ['year' => $label]),
            Workdays::next(self::legalPersonDate($end)),
            $label,
            $partnership
                ? __('Handelsbolaget lämnar särskilda uppgifter på Inkomstdeklaration 4, och delägarna deklarerar sin del själva. Datumet gäller både digitalt och på papper.')
                : __('Datumet beror på när räkenskapsåret slutar. Det gäller när deklarationen lämnas elektroniskt; på papper är det en månad tidigare.'),
            'accounting.incomeTax',
            self::ARTICLE_TAX,
            $partnership ? self::INCOME_TAX_HB : self::INCOME_TAX_AB,
        );
    }

    private static function workdayOrBefore(CarbonImmutable $date): CarbonImmutable
    {
        return Workdays::isWorkday($date) ? $date : Workdays::before($date);
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
}
