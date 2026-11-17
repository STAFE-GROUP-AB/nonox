<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use App\Domain\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/**
 * Unsocial time for the proposal's fairness measure (ADR-037 amendment,
 * gap 3): the minutes of a shift on weekday evenings and nights (from
 * $from until $to the next morning, by default 19:00–07:00), and every
 * minute on Saturdays, Sundays and public holidays. The company's own
 * definition, the same for everyone, so the share can be compared between
 * people on different agreements. Wall-clock times in Europe/Stockholm,
 * real elapsed minutes. Pure.
 */
final class UnsocialTime
{
    public const string ZONE = 'Europe/Stockholm';

    /** @var array<string, bool> */
    private static array $wholeDay = [];

    public static function minutes(CarbonImmutable $start, CarbonImmutable $end, string $from = '19:00', string $to = '07:00'): int
    {
        $start = $start->setTimezone(self::ZONE);
        $end = $end->setTimezone(self::ZONE);
        $a = $start->getTimestamp();
        $b = $end->getTimestamp();

        if ($b <= $a) {
            return 0;
        }

        [$fromHour, $fromMinute] = self::time($from);
        [$toHour, $toMinute] = self::time($to);
        $seconds = 0;

        for ($day = $start->startOfDay(); $day->getTimestamp() < $b; $day = $day->addDay()->startOfDay()) {
            $next = $day->addDay()->startOfDay();

            if (self::isWholeDay($day)) {
                $seconds += self::overlap($a, $b, $day->getTimestamp(), $next->getTimestamp());

                continue;
            }

            // The morning until $to, and the evening from $from until midnight.
            $seconds += self::overlap($a, $b, $day->getTimestamp(), $day->setTime($toHour, $toMinute)->getTimestamp());
            $seconds += self::overlap($a, $b, $day->setTime($fromHour, $fromMinute)->getTimestamp(), $next->getTimestamp());
        }

        return intdiv($seconds, 60);
    }

    /** Saturdays, Sundays and public holidays count in full. */
    private static function isWholeDay(CarbonImmutable $day): bool
    {
        return self::$wholeDay[$day->toDateString()] ??= $day->isWeekend() || SwedishHolidays::isPublicHoliday($day);
    }

    private static function overlap(int $a, int $b, int $from, int $to): int
    {
        return max(0, min($b, $to) - max($a, $from));
    }

    /** @return array{int, int} */
    private static function time(string $value): array
    {
        $parts = array_map('intval', explode(':', $value));

        return [max(0, min(23, $parts[0])), max(0, min(59, $parts[1] ?? 0))];
    }
}
