<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use App\Modules\Planning\Domain\Demand\DayBuckets;
use App\Modules\Planning\Domain\Demand\DayDemand;
use Carbon\CarbonImmutable;

/**
 * Turns a day's staffing gap into new shifts (v2 of the schedule proposal,
 * ADR-037 amendment gap 3, P8). Pure and deterministic.
 *
 * The gap of an hour is the required staff less the people planned and the
 * open shifts already there (the proposal fills those first), rounded up: an
 * hour needing 3 with 1,5 planned lacks 2. Hours that have started are left
 * out. Then, from the earliest hour with a gap, one shift at a time: of the
 * company's allowed lengths, the one that covers the most hours with a gap,
 * then wastes the fewest hours without one, then the shortest. A shift never
 * runs past midnight: one that would is moved earlier so it ends at
 * midnight. Each shift takes one person off the gap of every hour it covers
 * in full; the loop ends when no hour lacks anyone.
 *
 * A shift gets the company's break once for each time it would otherwise
 * run longer than the limit for work without a break (one break in an 8- or
 * 10-hour shift at five hours); breaks are not placed in time, as in the
 * demand itself, so break_minutes holds their sum.
 *
 * With demand per day only (no till and no own hour profile) only the
 * minimum staff has hours; the rest of the day's need cannot honestly be
 * placed in time, so it is returned as minutes for the page to name, never
 * turned into shifts (principle 3).
 */
final class DemandBlocks
{
    /** A bound on the loop; each round takes at least one person-hour off the gap. */
    public const int MAX_PER_DAY = 96;

    private const float EPSILON = 0.001;

    /**
     * @param  list<int>  $lengths  allowed lengths in minutes, whole hours
     * @param  ?int  $maxWithoutBreak  minutes in a row without a break; null gives no breaks
     * @return array{list<DemandBlock>, int, int} the new shifts in time order, the staff-minutes of a day per day only that are not placed, and the staff-minutes of the gap left without a shift: hours no allowed length fits, and what was left when MAX_PER_DAY stopped the loop
     */
    public static function forDay(DayDemand $day, ?int $areaId, array $lengths, int $breakMinutes, ?int $maxWithoutBreak, CarbonImmutable $notBefore): array
    {
        $date = CarbonImmutable::parse($day->date, DayBuckets::ZONE);
        $buckets = DayBuckets::of($date);
        $bucketLengths = DayBuckets::lengths($buckets);
        $dayStart = $date->startOfDay()->utc();
        $dayEnd = $date->startOfDay()->addDay()->utc();
        $lengths = array_values(array_unique(array_filter($lengths, fn (int $length): bool => $length > 0 && $length % 60 === 0)));
        sort($lengths);
        $gap = [];

        foreach ($day->hours as $hour => $row) {
            if ($row['need'] === null || ! isset($buckets[$hour]) || $buckets[$hour][0][0]->lessThan($notBefore)) {
                continue;
            }

            $gap[$hour] = max(0, (int) ceil($row['need'] - $row['planned'] - $row['open'] - self::EPSILON));
        }

        $blocks = [];
        $unfit = 0;

        while (count($blocks) < self::MAX_PER_DAY) {
            $first = null;

            foreach ($gap as $hour => $lacking) {
                if ($lacking > 0) {
                    $first = $hour;

                    break;
                }
            }

            if ($first === null) {
                break;
            }

            $best = null;

            foreach ($lengths as $length) {
                $start = $buckets[$first][0][0];
                $end = $start->addMinutes($length);

                if ($end->greaterThan($dayEnd)) {
                    $end = $dayEnd;
                    $start = $dayEnd->subMinutes($length);
                }

                if ($start->lessThan($dayStart) || $start->lessThan($notBefore)) {
                    continue;
                }

                $covered = [];

                foreach (DayBuckets::overlap($buckets, $start, $end) as $hour => $minutes) {
                    if ($minutes >= ($bucketLengths[$hour] ?? PHP_INT_MAX)) {
                        $covered[] = $hour;
                    }
                }

                $value = count(array_filter($covered, fn (int $hour): bool => ($gap[$hour] ?? 0) > 0));
                $key = [-$value, count($covered) - $value, $length];

                if ($value > 0 && ($best === null || $key < $best['key'])) {
                    $best = ['key' => $key, 'start' => $start, 'end' => $end, 'covered' => $covered, 'value' => $value, 'length' => $length];
                }
            }

            if ($best === null) {
                // No allowed length fits around the hour (the day is too short before it, or it would start in the past): left to the planner, and counted.
                $unfit += $gap[$first] * ($bucketLengths[$first] ?? 60);
                $gap[$first] = 0;

                continue;
            }

            foreach ($best['covered'] as $hour) {
                if (($gap[$hour] ?? 0) > 0) {
                    $gap[$hour]--;
                }
            }

            $blocks[] = new DemandBlock(
                $best['start']->setTimezone(DayBuckets::ZONE),
                $best['end']->setTimezone(DayBuckets::ZONE),
                $maxWithoutBreak !== null && $maxWithoutBreak > 0 ? max(0, (int) ceil($best['length'] / $maxWithoutBreak) - 1) * $breakMinutes : 0,
                $areaId,
                $day->date,
                $best['value'],
            );
        }

        usort($blocks, fn (DemandBlock $a, DemandBlock $b): int => [$a->start->getTimestamp(), $a->end->getTimestamp()] <=> [$b->start->getTimestamp(), $b->end->getTimestamp()]);
        $cut = $unfit;

        foreach ($gap as $hour => $lacking) {
            $cut += $lacking * ($bucketLengths[$hour] ?? 60);
        }

        return [$blocks, self::unplaced($day, $blocks), $cut];
    }

    /**
     * Per day only: the day's need in staff-minutes beyond what is planned,
     * open and now proposed. Zero when the day has hours or no need.
     *
     * @param  list<DemandBlock>  $blocks
     */
    private static function unplaced(DayDemand $day, array $blocks): int
    {
        if ($day->shape !== null || $day->needMinutes === null) {
            return 0;
        }

        $proposed = array_sum(array_map(fn (DemandBlock $block): int => intdiv($block->end->getTimestamp() - $block->start->getTimestamp(), 60), $blocks));

        return max(0, $day->needMinutes - $day->plannedMinutes - $day->openMinutes - $proposed);
    }
}
