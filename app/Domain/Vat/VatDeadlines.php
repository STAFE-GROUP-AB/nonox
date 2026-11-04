<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Deadlines\Workdays;
use Carbon\CarbonImmutable;

/**
 * The last day a VAT period must be declared and paid, from SFL 26 kap. 26,
 * 33, 33 a and 33 b §§ and Skatteverket's "När ska jag deklarera moms"
 * (both read 2026-11-02) and the skattekonto brochure SKV 408 utgåva 13:
 *
 * - Monthly, turnover up to 40 million kronor: the 12th of the second month
 *   after the period, the 17th in January and August.
 * - Monthly, turnover above 40 million kronor: the 26th of the month after
 *   the period, the 27th in December.
 * - Quarterly: the 12th of the second month after the quarter, the 17th in
 *   August.
 * - Yearly (#60):
 *   - with EU trade during the year, for trading and limited partnerships
 *     (HB, KB), and for an ideell förening or stiftelse that does not file
 *     an income tax return (SFL 30 kap. 4 §; unknown counts as not): the
 *     26th of the second month after the year, the 27th in December (33 §,
 *     33 a § andra stycket, 33 b § tredje stycket);
 *   - a sole trader (EF): 12 May the year after (33 a §);
 *   - other legal persons (AB, ekonomisk förening, ideell förening,
 *     stiftelse): by the month the fiscal year ends, as Skatteverket groups
 *     them, on paper / electronically (33 b §):
 *     January–April 12 November / 12 December, May–June 27 December /
 *     17 January, July–August 12 March / 12 April, September–December
 *     12 July / 17 August.
 * - A day on a Saturday, a Sunday, a holiday, midsommarafton, julafton or
 *   nyårsafton moves to the next weekday (lag (1930:173) 2 §, Workdays).
 *
 * 33 b § names four year ends (31 December, 30 April, 30 June, 31 August);
 * a year ending on another day counts as ending on the next of them (SFL 3
 * kap. 5 §), which gives Skatteverket's month ranges (REGLER.md §1). Not
 * handled: a monthly filer who has asked to declare on the 26th (26 kap.
 * 28 §), a decision under 33 c § (the earlier date on request) and the
 * catch-up dates of 29 and 33 e §§, byråanstånd for a sole trader (26
 * June), a juridisk person that does not file an income tax return (33 §),
 * and dödsbon. Which trade counts as EU trade is decided by
 * the caller (EuTrade); distance sales of goods through OSS are not counted.
 *
 * @see https://www.skatteverket.se/foretag/moms/deklareramoms/narskajagdeklareramoms.4.6d02084411db6e252fe80008988.html
 * @see https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/skatteforfarandelag-20111244_sfs-2011-1244/
 */
final class VatDeadlines
{
    /**
     * @param  ?LegalForm  $legalForm  needed for yearly VAT; without it no yearly date is given
     * @param  bool  $euTrade  EU trade during a yearly period (33 a § andra stycket)
     * @param  bool  $electronic  the return is filed electronically (33 b § andra stycket)
     * @param  ?bool  $filesIncomeTaxReturn  for an ideell förening or stiftelse: whether it files an income tax return; unknown (null) gives the earlier 33 § date
     */
    public static function for(
        VatPeriod $vatPeriod,
        CarbonImmutable $periodEnd,
        bool $turnoverOver40Msek,
        ?LegalForm $legalForm = null,
        bool $euTrade = false,
        bool $electronic = true,
        ?bool $filesIncomeTaxReturn = null,
    ): ?CarbonImmutable {
        $month = $periodEnd->startOfMonth();

        $day = match (true) {
            $vatPeriod === VatPeriod::Monthly && $turnoverOver40Msek => self::onDay($month->addMonthNoOverflow(), 26, [12 => 27]),
            $vatPeriod === VatPeriod::Monthly => self::onDay($month->addMonthsNoOverflow(2), 12, [1 => 17, 8 => 17]),
            $vatPeriod === VatPeriod::Quarterly => self::onDay($month->addMonthsNoOverflow(2), 12, [8 => 17]),
            $vatPeriod === VatPeriod::Yearly => self::yearly($month, $legalForm, $euTrade, $electronic, $filesIncomeTaxReturn),
            default => null,
        };

        return $day === null ? null : Workdays::next($day);
    }

    private static function yearly(CarbonImmutable $lastMonth, ?LegalForm $legalForm, bool $euTrade, bool $electronic, ?bool $filesIncomeTaxReturn): ?CarbonImmutable
    {
        if ($legalForm === null) {
            return null;
        }

        // 33 b § applies only to those who file an income tax return; an ideell förening or stiftelse may not (SFL 30 kap. 4 §).
        $notFiling = in_array($legalForm, [LegalForm::IdeellForening, LegalForm::Stiftelse], true) && $filesIncomeTaxReturn !== true;

        if ($euTrade || $notFiling || $legalForm === LegalForm::Hb || $legalForm === LegalForm::Kb) {
            return self::onDay($lastMonth->addMonthsNoOverflow(2), 26, [12 => 27]);
        }

        if ($legalForm === LegalForm::Ef) {
            return $lastMonth->setDate($lastMonth->year + 1, 5, 12);
        }

        // Months after the fiscal year's last month: paper, then electronic one month later.
        $after = match (true) {
            $lastMonth->month <= 4 => 11 - $lastMonth->month,
            $lastMonth->month <= 6 => 12 - $lastMonth->month,
            $lastMonth->month <= 8 => 15 - $lastMonth->month,
            default => 19 - $lastMonth->month,
        };

        $due = $lastMonth->addMonthsNoOverflow($after + ($electronic ? 1 : 0));

        return self::onDay($due, 12, $electronic ? [1 => 17, 8 => 17] : [12 => 27]);
    }

    /**
     * @param  array<int, int>  $exceptions  day by month number
     */
    private static function onDay(CarbonImmutable $month, int $day, array $exceptions): CarbonImmutable
    {
        return $month->setDay($exceptions[$month->month] ?? $day);
    }
}
