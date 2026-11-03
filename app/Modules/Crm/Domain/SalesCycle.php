<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The length of the sales cycle (ADR-043 stage 2): the calendar days, in
 * Swedish time, from the day a deal was created to the day it was won. A
 * deal won the day it was created took 0 days; the clock change in spring
 * and autumn does not shorten or lengthen a day.
 */
final class SalesCycle
{
    public const string ZONE = 'Europe/Stockholm';

    public static function days(DateTimeInterface $created, DateTimeInterface $won): int
    {
        $from = CarbonImmutable::instance($created)->setTimezone(self::ZONE)->startOfDay();
        $to = CarbonImmutable::instance($won)->setTimezone(self::ZONE)->startOfDay();

        // Calendar dates, compared at noon UTC so a 23- or 25-hour day still counts as one.
        return (int) CarbonImmutable::parse($from->toDateString().' 12:00', 'UTC')
            ->diffInDays(CarbonImmutable::parse($to->toDateString().' 12:00', 'UTC'), false);
    }

    /**
     * The average in days, to one decimal, or null when no deal was won.
     *
     * @param  iterable<array{0: DateTimeInterface, 1: DateTimeInterface}>  $deals  [created, won]
     */
    public static function averageDays(iterable $deals): ?float
    {
        $total = 0;
        $count = 0;

        foreach ($deals as [$created, $won]) {
            // A won date before the creation (imported data) counts as the same day.
            $total += max(0, self::days($created, $won));
            $count++;
        }

        return $count === 0 ? null : round($total / $count, 1);
    }
}
