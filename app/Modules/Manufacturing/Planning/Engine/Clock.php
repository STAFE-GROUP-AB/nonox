<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

use Carbon\CarbonImmutable;

/**
 * The scheduler counts time in whole minutes since the Unix epoch, so a
 * plan is plain integer arithmetic. Working hours are local times in
 * Europe/Stockholm and are turned into minutes per date, which keeps the
 * change to and from summer time right.
 */
final class Clock
{
    public const string ZONE = 'Europe/Stockholm';

    public static function minute(CarbonImmutable $at): int
    {
        return intdiv($at->getTimestamp(), 60);
    }

    public static function at(int $minute): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($minute * 60, self::ZONE);
    }

    /** The minute a local wall-clock time on a date begins: ("2026-11-16", 420) → 07:00 that day. */
    public static function local(string $date, int $minuteOfDay): int
    {
        $day = CarbonImmutable::parse($date, self::ZONE)->startOfDay();

        return self::minute($day->setTime(intdiv($minuteOfDay, 60), $minuteOfDay % 60));
    }

    /** The local date (Y-m-d) of a minute. */
    public static function date(int $minute): string
    {
        return self::at($minute)->toDateString();
    }

    /** "07:30" → 450; null when it is not a time of day. */
    public static function minuteOfDay(?string $time): ?int
    {
        if ($time === null || preg_match('/^([01]\d|2[0-4]):([0-5]\d)$/', $time, $match) !== 1) {
            return null;
        }

        $minutes = (int) $match[1] * 60 + (int) $match[2];

        return $minutes > 1440 ? null : $minutes;
    }

    /** 450 → "07:30". */
    public static function timeOfDay(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }
}
