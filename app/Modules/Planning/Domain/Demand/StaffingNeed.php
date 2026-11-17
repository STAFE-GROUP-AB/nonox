<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Demand;

use App\Modules\Planning\Models\StaffingRule;
use Carbon\CarbonImmutable;

/**
 * Turns a day's forecast into required staff (ADR-037, gap 1):
 *
 * - Per hour, where the sales have a shape: required = max(minimum staff,
 *   ceil(the hour's sales ÷ the sales one staffed hour should carry)).
 * - Per day only, without a shape: required staff-hours = ceil(the day's
 *   sales ÷ the target), and at least the minimum staff summed over the
 *   hours; an hour then shows its minimum staff only when it has one (else
 *   no figure), and can only fall short of it.
 *
 * The rule for an hour is the newest one valid that day whose window holds
 * the hour's start (latest valid-from first, a named weekday before "every
 * day", then the latest saved). Planned staff are the minutes people's
 * shifts cover in the hour, divided by its length; breaks are not placed in
 * time, so they are not taken off. An hour is under-staffed when planned is
 * below required, and over-staffed when at least one more person is planned
 * than its sales target needs (a minimum staff alone is never exceeded).
 * With hours, the hours decide the day; per day only, the planned minutes
 * inside the targeted hours are compared with the day's need. Time outside
 * every target's window counts for neither. Warnings, never blocks.
 */
final class StaffingNeed
{
    /**
     * @param  iterable<StaffingRule>  $rules  the scope's rules
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $staffed  intervals of shifts with a person
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $open  intervals of open shifts
     */
    public static function day(CarbonImmutable $date, Forecast $forecast, ?HourShape $shape, iterable $rules, array $staffed, array $open): DayDemand
    {
        $buckets = DayBuckets::of($date);
        $lengths = DayBuckets::lengths($buckets);
        $hours = array_keys($buckets);
        $applicable = self::applicable($date, $rules);
        // A shape is used only when there is a figure to spread.
        $used = $forecast->amountOre !== null ? $shape : null;
        $sales = $used?->split((int) $forecast->amountOre, $hours);
        $planned = self::covered($buckets, $staffed);
        $opened = self::covered($buckets, $open);
        $rows = [];
        $floorMinutes = 0;
        $hourlyNeedMinutes = 0;
        // Planned minutes inside the hours a target covers: time outside them (preparing, closing) is never over-staffing.
        $plannedInTargets = 0;

        foreach ($hours as $hour) {
            $minutes = $lengths[$hour];
            $rule = self::ruleFor($hour * 60, $applicable);
            $staff = $minutes > 0 ? ($planned[$hour] ?? 0) / $minutes : 0.0;
            $need = null;
            $status = DayDemand::NONE;

            if ($rule !== null) {
                $floor = $rule->min_staff ?? 0;
                $floorMinutes += $floor * $minutes;
                $plannedInTargets += $planned[$hour] ?? 0;

                if ($sales !== null) {
                    // Per hour: the hour's sales against the target, never below the floor.
                    $byTarget = $rule->sales_per_staff_hour_ore !== null
                        ? self::ceilDiv($sales[$hour] * 60, $rule->sales_per_staff_hour_ore * $minutes)
                        : 0;
                    $need = max($floor, $byTarget);
                    $status = self::status($need, $staff, $rule->sales_per_staff_hour_ore !== null);
                } elseif ($floor > 0) {
                    // Per day only: the hour shows its floor, and can only fall short of it.
                    $need = $floor;
                    $status = $staff + 0.001 < $floor ? DayDemand::UNDER : DayDemand::OK;
                }

                $hourlyNeedMinutes += ($need ?? 0) * $minutes;
            }

            $rows[$hour] = [
                'hour' => $hour,
                'minutes' => $minutes,
                'sales' => $sales[$hour] ?? null,
                'need' => $need,
                'planned' => round($staff, 1),
                'open' => $minutes > 0 ? round(($opened[$hour] ?? 0) / $minutes, 1) : 0.0,
                'status' => $status,
            ];
        }

        $needMinutes = null;
        $target = self::dailyTarget($applicable);
        $perDay = $sales === null;

        if ($applicable !== []) {
            $needMinutes = $hourlyNeedMinutes;

            // Per day only: the day's sales against the newest target, never below the floors.
            if ($perDay && $target !== null && $forecast->amountOre !== null) {
                $needMinutes = max($floorMinutes, self::ceilDiv($forecast->amountOre, $target) * 60);
            }
        }

        $statuses = array_column($rows, 'status');
        $status = DayDemand::NONE;

        if ($needMinutes !== null) {
            $status = match (true) {
                // Per hour: the hours decide.
                ! $perDay => in_array(DayDemand::UNDER, $statuses, true) ? DayDemand::UNDER : (in_array(DayDemand::OVER, $statuses, true) ? DayDemand::OVER : DayDemand::OK),
                // Per day only: a floor not met, or the targeted hours' staff-hours against the need.
                in_array(DayDemand::UNDER, $statuses, true), $plannedInTargets < $needMinutes => DayDemand::UNDER,
                $target !== null && $plannedInTargets >= $needMinutes + 60 => DayDemand::OVER,
                default => DayDemand::OK,
            };
        }

        $plannedMinutes = $applicable === [] ? (int) array_sum($planned) : $plannedInTargets;

        return new DayDemand(
            date: $date->toDateString(),
            forecast: $forecast,
            shape: $used?->source,
            hours: $rows,
            needMinutes: $needMinutes,
            plannedMinutes: $plannedMinutes,
            openMinutes: (int) array_sum($opened),
            status: $status,
            hasRules: $applicable !== [],
        );
    }

    /**
     * The rules valid on the day, newest first.
     *
     * @param  iterable<StaffingRule>  $rules
     * @return list<StaffingRule>
     */
    public static function applicable(CarbonImmutable $date, iterable $rules): array
    {
        $weekday = $date->isoWeekday();
        $day = $date->toDateString();
        $found = [];

        foreach ($rules as $rule) {
            if (($rule->weekday === null || $rule->weekday === $weekday) && $rule->valid_from->toDateString() <= $day) {
                $found[] = $rule;
            }
        }

        usort($found, fn (StaffingRule $a, StaffingRule $b): int => [$b->valid_from->toDateString(), $b->weekday !== null, $b->id] <=> [$a->valid_from->toDateString(), $a->weekday !== null, $a->id]);

        return $found;
    }

    /** @param  list<StaffingRule>  $applicable  newest first */
    private static function ruleFor(int $minute, array $applicable): ?StaffingRule
    {
        foreach ($applicable as $rule) {
            if ($rule->start_minute <= $minute && $minute < $rule->end_minute) {
                return $rule;
            }
        }

        return null;
    }

    /** @param  list<StaffingRule>  $applicable  newest first */
    private static function dailyTarget(array $applicable): ?int
    {
        foreach ($applicable as $rule) {
            if ($rule->sales_per_staff_hour_ore !== null && $rule->sales_per_staff_hour_ore > 0) {
                return $rule->sales_per_staff_hour_ore;
            }
        }

        return null;
    }

    /**
     * @param  array<int, list<array{CarbonImmutable, CarbonImmutable}>>  $buckets
     * @param  list<array{CarbonImmutable, CarbonImmutable}>  $intervals
     * @return array<int, int> local hour => minutes covered
     */
    private static function covered(array $buckets, array $intervals): array
    {
        $covered = [];

        foreach ($intervals as [$from, $to]) {
            foreach (DayBuckets::overlap($buckets, $from, $to) as $hour => $minutes) {
                $covered[$hour] = ($covered[$hour] ?? 0) + $minutes;
            }
        }

        return $covered;
    }

    /** Over-staffing is judged only against a sales target: a minimum staff is a floor, not a ceiling. */
    private static function status(int $need, float $staff, bool $hasTarget): string
    {
        if ($staff + 0.001 < $need) {
            return DayDemand::UNDER;
        }

        return $hasTarget && $staff >= $need + 1 ? DayDemand::OVER : DayDemand::OK;
    }

    private static function ceilDiv(int $numerator, int $denominator): int
    {
        if ($numerator <= 0 || $denominator <= 0) {
            return 0;
        }

        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
