<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Domain\Company\VatPeriod;
use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/**
 * The last day a VAT period must be declared and paid, from Skatteverket's
 * rules ("När ska jag deklarera moms", and the skattekonto brochure SKV 408
 * utgåva 13, 2026), checked 2026-10-31:
 *
 * - Monthly, turnover up to 40 million kronor: the 12th of the second month
 *   after the period, the 17th in January and August.
 * - Monthly, turnover above 40 million kronor: the 26th of the month after
 *   the period, the 27th in December.
 * - Quarterly: the 12th of the second month after the quarter, the 17th in
 *   August.
 * - A 12th, 17th or 26th on a holiday or weekend moves to the next weekday.
 *
 * TODO(legal-verify): yearly VAT depends on the legal form, EU trade and
 * how the income tax return is filed; no date is given for it.
 *
 * @see https://www.skatteverket.se/foretag/moms/deklareramoms/narskajagdeklareramoms.4.6d02084411db6e252fe80008988.html
 */
final class VatDeadlines
{
    public static function for(VatPeriod $vatPeriod, CarbonImmutable $periodEnd, bool $turnoverOver40Msek): ?CarbonImmutable
    {
        $month = $periodEnd->startOfMonth();

        $day = match (true) {
            $vatPeriod === VatPeriod::Monthly && $turnoverOver40Msek => self::onDay($month->addMonthNoOverflow(), 26, [12 => 27]),
            $vatPeriod === VatPeriod::Monthly => self::onDay($month->addMonthsNoOverflow(2), 12, [1 => 17, 8 => 17]),
            $vatPeriod === VatPeriod::Quarterly => self::onDay($month->addMonthsNoOverflow(2), 12, [8 => 17]),
            default => null,
        };

        while ($day !== null && ($day->isWeekend() || SwedishHolidays::isHoliday($day))) {
            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * @param  array<int, int>  $exceptions  day by month number
     */
    private static function onDay(CarbonImmutable $month, int $day, array $exceptions): CarbonImmutable
    {
        return $month->setDay($exceptions[$month->month] ?? $day);
    }
}
