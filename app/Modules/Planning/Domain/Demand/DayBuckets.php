<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Demand;

use Carbon\CarbonImmutable;

/**
 * The hours of a day in Sweden, keyed by the local hour (0–23), each with
 * the real intervals it covers. A normal day has 24 buckets of 60 minutes.
 * The spring day has no 02 bucket (23 hours); the autumn day's 02 bucket
 * holds two real hours (25 hours), so a sale or a shift in either lands in
 * 02 and the bucket is 120 minutes long (ADR-037, gap 1: local time).
 */
final class DayBuckets
{
    public const string ZONE = 'Europe/Stockholm';

    /** @return array<int, list<array{CarbonImmutable, CarbonImmutable}>> local hour => real intervals, in order */
    public static function of(CarbonImmutable $date): array
    {
        $start = CarbonImmutable::create($date->year, $date->month, $date->day, 0, 0, 0, self::ZONE);
        $end = $start?->addDay()->startOfDay();

        if ($start === null || $end === null) {
            return [];
        }

        $buckets = [];

        for ($moment = $start->utc(); $moment->lessThan($end->utc()); $moment = $moment->addHour()) {
            $buckets[(int) $moment->setTimezone(self::ZONE)->format('G')][] = [$moment, $moment->addHour()];
        }

        return $buckets;
    }

    /**
     * Minutes of [from, to) that fall inside each bucket.
     *
     * @param  array<int, list<array{CarbonImmutable, CarbonImmutable}>>  $buckets
     * @return array<int, int> local hour => minutes
     */
    public static function overlap(array $buckets, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $minutes = [];
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();

        foreach ($buckets as $hour => $intervals) {
            foreach ($intervals as [$start, $end]) {
                $seconds = min($toTs, $end->getTimestamp()) - max($fromTs, $start->getTimestamp());

                if ($seconds > 0) {
                    $minutes[$hour] = ($minutes[$hour] ?? 0) + intdiv($seconds, 60);
                }
            }
        }

        return $minutes;
    }

    /**
     * @param  array<int, list<array{CarbonImmutable, CarbonImmutable}>>  $buckets
     * @return array<int, int> local hour => length in minutes
     */
    public static function lengths(array $buckets): array
    {
        return array_map(fn (array $intervals): int => count($intervals) * 60, $buckets);
    }
}
