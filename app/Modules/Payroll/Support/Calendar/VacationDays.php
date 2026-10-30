<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * Which days of a vacation count as semesterdagar, by the company's method.
 *
 * NET (begränsad nettometod, the default): only the days the employee is
 * scheduled to work count, and someone working fewer than five days a week
 * earns proportionally fewer days (25 × days a week ÷ 5), so a part-time
 * week off costs the same share of the year as a full-time one.
 *
 * GROSS (semesterlagen 3 a and 9 §§ as written):
 * - Monday to Friday count, except public holidays, midsommarafton, julafton
 *   and nyårsafton.
 * - A Saturday or Sunday counts when the vacation is shorter than five days
 *   and the day would otherwise have been a working day (9 § tredje stycket).
 *   From five days the employee instead gets the weekend off, before or
 *   after, and a weekday off in its place counts as usual (9 § första och
 *   andra stycket).
 *
 * TODO(legal-verify): which method a collective agreement prescribes, and a
 * vacation split over two months judged per month in the payroll run.
 */
final class VacationDays
{
    /** Vacations of at least this many weekdays give weekend time off instead of weekend semesterdagar. */
    public const int WEEKEND_RULE_DAYS = 5;

    /** The working week the 25 days of semesterlagen are counted on. */
    public const int FULL_WEEK_DAYS = 5;

    /** Every weekday counts, with the weekend rule of 9 § (semesterlagen as written). */
    public const string GROSS = 'GROSS';

    /** Only the days the employee is scheduled to work count, and the days earned are scaled to the working week (begränsad nettometod). */
    public const string NET = 'NET';

    /**
     * The days that count, from one continuous vacation.
     *
     * @param  list<CarbonImmutable>  $days  every calendar day of the vacation, in order
     * @param  callable(CarbonImmutable): bool  $scheduled  whether the employee would have worked that day
     * @return list<CarbonImmutable>
     */
    public static function counted(array $days, callable $scheduled, string $method = self::NET): array
    {
        if ($method === self::NET) {
            // Midsommarafton, julafton and nyårsafton are treated like a Sunday (3 a §) under either method.
            return array_values(array_filter($days, fn (CarbonImmutable $day): bool => $scheduled($day) && ! self::holidayOrEve($day)));
        }

        $weekdays = array_values(array_filter($days, fn (CarbonImmutable $day): bool => SwedishHolidays::isVacationDay($day)));

        if (count($weekdays) >= self::WEEKEND_RULE_DAYS) {
            return $weekdays;
        }

        return array_values(array_filter($days, fn (CarbonImmutable $day): bool => SwedishHolidays::isVacationDay($day)
            || ($day->isWeekend() && $scheduled($day) && ! self::holidayOrEve($day))));
    }

    /**
     * How many days a week the schedule has working hours; five without a schedule.
     *
     * @param  ?list<string>  $weekPattern
     */
    public static function daysPerWeek(?array $weekPattern): int
    {
        if ($weekPattern === null) {
            return self::FULL_WEEK_DAYS;
        }

        return max(1, count(array_filter($weekPattern, fn (string $hours): bool => (float) $hours > 0)));
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
