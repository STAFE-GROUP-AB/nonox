<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Calendar;

use App\Domain\Calendar\SwedishHolidays as Holidays;
use Carbon\CarbonImmutable;

/**
 * Payroll's view of the public holidays; the calendar itself (lagen
 * (1989:253) om allmänna helgdagar) lives in App\Domain\Calendar\SwedishHolidays.
 */
final class SwedishHolidays
{
    public static function isHoliday(CarbonImmutable $date): bool
    {
        return Holidays::isHoliday($date);
    }

    /**
     * Whether a day can be a semesterdag (semesterlagen 3 a §): not Saturday
     * or Sunday, and not a day treated like Sunday, that is a public holiday,
     * midsommarafton, julafton or nyårsafton.
     *
     * TODO(legal-verify): 3 a § allows Saturdays and Sundays as semesterdagar
     * in the cases of 9 § tredje stycket (schedules with weekend work).
     */
    public static function isVacationDay(CarbonImmutable $date): bool
    {
        if ($date->isWeekend() || self::isHoliday($date)) {
            return false;
        }

        $eves = Holidays::eves($date->year);

        return ! in_array($date->toDateString(), [$eves['MIDSOMMARAFTON'], $eves['JULAFTON'], $eves['NYARSAFTON']], true);
    }

    /** @return list<string> the law's dated holidays, Y-m-d */
    public static function forYear(int $year): array
    {
        return Holidays::forYear($year);
    }
}
