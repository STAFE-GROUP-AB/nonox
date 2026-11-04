<?php

declare(strict_types=1);

namespace App\Domain\Deadlines;

use App\Domain\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/**
 * Lag (1930:173) om beräkning av lagstadgad tid 2 §: when the last day for
 * an action falls on a Saturday, a Sunday, another public holiday,
 * midsommarafton, julafton or nyårsafton, it may be taken the next weekday
 * (docs/foretagsguiden/datum/REGLER.md §0.1).
 */
final class Workdays
{
    /** The eves the law counts as non-working days; påskafton is a Saturday anyway. */
    private const array EVES = ['MIDSOMMARAFTON', 'JULAFTON', 'NYARSAFTON'];

    public static function isWorkday(CarbonImmutable $date): bool
    {
        if ($date->isWeekend() || SwedishHolidays::isHoliday($date)) {
            return false;
        }

        $eves = SwedishHolidays::eves($date->year);

        return ! in_array($date->toDateString(), array_map(fn (string $key): string => $eves[$key] ?? '', self::EVES), true);
    }

    /** The date itself when it is a working day, else the next working day. */
    public static function next(CarbonImmutable $date): CarbonImmutable
    {
        while (! self::isWorkday($date)) {
            $date = $date->addDay();
        }

        return $date;
    }

    /** The last working day before the date. */
    public static function before(CarbonImmutable $date): CarbonImmutable
    {
        $date = $date->subDay();

        while (! self::isWorkday($date)) {
            $date = $date->subDay();
        }

        return $date;
    }

    /**
     * The day in the month, with the law's exceptions by month number, moved to a working day.
     *
     * @param  array<int, int>  $exceptions  day by month number
     */
    public static function onDay(CarbonImmutable $month, int $day, array $exceptions = []): CarbonImmutable
    {
        return self::next($month->startOfMonth()->setDay($exceptions[$month->month] ?? $day));
    }
}
