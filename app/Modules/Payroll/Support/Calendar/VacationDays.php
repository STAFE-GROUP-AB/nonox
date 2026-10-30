<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * Which days of a vacation count as semesterdagar (semesterlagen 3 a, 9 §§).
 *
 * - Monday to Friday count, except public holidays, midsommarafton, julafton
 *   and nyårsafton.
 * - A Saturday or Sunday counts when the vacation is shorter than five days
 *   and the day would otherwise have been a working day (9 § tredje stycket).
 *   From five days the employee instead gets the weekend off, before or
 *   after, and a weekday off in its place counts as usual (9 § första och
 *   andra stycket).
 *
 * TODO(legal-verify): a vacation split over two months is judged per month
 * in the payroll run.
 */
final class VacationDays
{
    /** Vacations of at least this many weekdays give weekend time off instead of weekend semesterdagar. */
    public const int WEEKEND_RULE_DAYS = 5;

    /**
     * The days that count, from one continuous vacation.
     *
     * @param  list<CarbonImmutable>  $days  every calendar day of the vacation, in order
     * @param  callable(CarbonImmutable): bool  $scheduled  whether the employee would have worked that day
     * @return list<CarbonImmutable>
     */
    public static function counted(array $days, callable $scheduled): array
    {
        $weekdays = array_values(array_filter($days, fn (CarbonImmutable $day): bool => SwedishHolidays::isVacationDay($day)));

        if (count($weekdays) >= self::WEEKEND_RULE_DAYS) {
            return $weekdays;
        }

        return array_values(array_filter($days, fn (CarbonImmutable $day): bool => SwedishHolidays::isVacationDay($day)
            || ($day->isWeekend() && $scheduled($day) && ! self::holidayOrEve($day))));
    }

    /**
     * Continuous runs of days.
     *
     * @param  list<CarbonImmutable>  $days  sorted
     * @return list<list<CarbonImmutable>>
     */
    public static function spells(array $days): array
    {
        $spells = [];
        $current = [];

        foreach ($days as $day) {
            if ($current !== [] && ! end($current)->addDay()->isSameDay($day)) {
                $spells[] = $current;
                $current = [];
            }

            $current[] = $day;
        }

        if ($current !== []) {
            $spells[] = $current;
        }

        return $spells;
    }

    /** A public holiday other than an ordinary Sunday, or one of the three eves. */
    private static function holidayOrEve(CarbonImmutable $day): bool
    {
        if (in_array($day->toDateString(), SwedishHolidays::forYear($day->year), true)) {
            return true;
        }

        return in_array($day->format('m-d'), ['12-24', '12-31'], true)
            || $day->addDay()->toDateString() === SwedishHolidays::forYear($day->year)[9];
    }
}
