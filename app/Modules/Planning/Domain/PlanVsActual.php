<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use Carbon\CarbonImmutable;

/**
 * Matches one employee's staff-ledger shifts to their planned shifts and
 * classifies each day (PLANNING_MODULE.md §5): on time, early or late start,
 * early or late finish, missing check-in or check-out, unplanned work and
 * no-show. Pure: no database.
 *
 * - A ledger shift belongs to the planned shift it overlaps most (a shift
 *   with only one side counts as long as the planned one); one that overlaps
 *   none but starts or ends within MATCH_WINDOW_MINUTES of it still matches.
 * - Check-in and check-out times are first rounded to the company's step
 *   (none by default), then compared with the plan. A difference under the
 *   attest tolerance is accepted silently: the planned time is paid.
 *   Otherwise the checked-in time is paid and the day has a deviation.
 * - A matched shift keeps the planned break; unplanned work has none until
 *   the manager says otherwise. The manager's own time keeps its own break.
 * - Without the ledger (module off, or the employee not in it) the actual
 *   time is the planned time. The manager's own actual time for a day
 *   replaces the ledger's for that day and is classified the same way.
 */
final class PlanVsActual
{
    public const int MATCH_WINDOW_MINUTES = 180;

    private const string ZONE = 'Europe/Stockholm';

    /**
     * @param  list<WorkInterval>  $planned  the employee's planned shifts
     * @param  ?list<WorkInterval>  $ledger  the employee's ledger shifts; null when the ledger is not used for them
     * @param  array<string, list<WorkInterval>>  $manual  the manager's actual time by date (Y-m-d); an empty list is a day without work
     * @param  array<string, true>  $absenceDays  dates with approved absence in payroll
     * @return array<string, DayComparison> by date, in date order
     */
    public static function compare(array $planned, ?array $ledger, array $manual = [], array $absenceDays = [], int $toleranceMinutes = 0, int $roundingMinutes = 0): array
    {
        usort($planned, fn (WorkInterval $a, WorkInterval $b): int => $a->anchor() <=> $b->anchor());
        $byDay = [];

        foreach ($planned as $interval) {
            $byDay[self::date($interval->anchor())]['planned'][] = $interval;
        }

        if ($ledger !== null) {
            [$pairs, $unmatched] = self::match($planned, $ledger);

            foreach ($pairs as [$plan, $actual]) {
                $byDay[self::date($plan->anchor())]['pairs'][] = [$plan, $actual];
            }

            foreach ($unmatched as $actual) {
                $byDay[self::date($actual->anchor())]['unplanned'][] = $actual;
            }
        }

        foreach (array_keys($manual) as $date) {
            $byDay[$date] ??= [];
        }

        ksort($byDay);
        $days = [];

        foreach ($byDay as $date => $day) {
            $plans = $day['planned'] ?? [];
            $date = (string) $date;
            $carbon = CarbonImmutable::parse($date, self::ZONE)->startOfDay();

            if (array_key_exists($date, $manual)) {
                [$pairs, $unmatched] = self::match($plans, $manual[$date]);
                $days[$date] = self::classify($carbon, $plans, $pairs, $unmatched, DayComparison::SOURCE_MANUAL, $absenceDays, $toleranceMinutes, 0)
                    ->with([Deviation::info(Deviation::MANUAL, __('Faktisk tid är ändrad för hand i Planering.'))]);

                continue;
            }

            if ($ledger === null) {
                $days[$date] = new DayComparison($carbon, $plans, $plans, DayComparison::SOURCE_PLAN, []);

                continue;
            }

            $days[$date] = self::classify($carbon, $plans, $day['pairs'] ?? [], $day['unplanned'] ?? [], DayComparison::SOURCE_LEDGER, $absenceDays, $toleranceMinutes, $roundingMinutes);
        }

        return $days;
    }

    /**
     * @param  list<WorkInterval>  $plans
     * @param  list<array{WorkInterval, WorkInterval}>  $pairs
     * @param  list<WorkInterval>  $unplanned
     * @param  array<string, true>  $absenceDays
     */
    private static function classify(CarbonImmutable $date, array $plans, array $pairs, array $unplanned, string $source, array $absenceDays, int $tolerance, int $rounding): DayComparison
    {
        $actual = [];
        $deviations = [];
        $matched = [];

        foreach ($pairs as [$plan, $worked]) {
            $matched[] = $plan;
            [$interval, $found] = self::pair($plan, $worked, $tolerance, $rounding, $source === DayComparison::SOURCE_MANUAL);
            $actual[] = $interval;
            array_push($deviations, ...$found);
        }

        foreach ($plans as $plan) {
            if (in_array($plan, $matched, true)) {
                continue;
            }

            $deviations[] = isset($absenceDays[$date->toDateString()])
                ? Deviation::info(Deviation::ABSENCE, __('Frånvaro är registrerad i lönen. Lönen räknar den.'))
                : new Deviation(Deviation::NO_SHOW, __('Planerat :from–:to men ingen arbetad tid.', ['from' => self::time($plan->start), 'to' => self::time($plan->end)]));
        }

        foreach ($unplanned as $worked) {
            if (! $worked->isComplete()) {
                $deviations[] = self::missing($worked);
                $actual[] = $worked;

                continue;
            }

            $interval = new WorkInterval(self::round($worked->start, $rounding), self::round($worked->end, $rounding), $worked->breakMinutes);
            $actual[] = $interval;
            $deviations[] = new Deviation(Deviation::UNPLANNED, __('Arbetade :from–:to utan planerat pass.', ['from' => self::time($interval->start), 'to' => self::time($interval->end)]));
        }

        usort($actual, fn (WorkInterval $a, WorkInterval $b): int => $a->anchor() <=> $b->anchor());

        return new DayComparison($date, $plans, $actual, $source, $deviations);
    }

    /**
     * The interval to pay for a planned shift and what was worked, and its deviations.
     *
     * @return array{WorkInterval, list<Deviation>}
     */
    private static function pair(WorkInterval $plan, WorkInterval $worked, int $tolerance, int $rounding, bool $ownBreak): array
    {
        if (! $worked->isComplete()) {
            return [$worked, [self::missing($worked)]];
        }

        $deviations = [];
        $start = self::round($worked->start, $rounding);
        $end = self::round($worked->end, $rounding);
        $startDiff = self::minutes($plan->start, $start);
        $endDiff = self::minutes($plan->end, $end);

        if (self::within($startDiff, $tolerance)) {
            $start = $plan->start;
        } else {
            $deviations[] = $startDiff < 0
                ? new Deviation(Deviation::EARLY_START, __('Började :minutes min tidigt (planerat :planned, incheckad :actual).', ['minutes' => -$startDiff, 'planned' => self::time($plan->start), 'actual' => self::time($start)]))
                : new Deviation(Deviation::LATE_START, __('Började :minutes min sent (planerat :planned, incheckad :actual).', ['minutes' => $startDiff, 'planned' => self::time($plan->start), 'actual' => self::time($start)]));
        }

        if (self::within($endDiff, $tolerance)) {
            $end = $plan->end;
        } else {
            $deviations[] = $endDiff < 0
                ? new Deviation(Deviation::EARLY_FINISH, __('Slutade :minutes min tidigt (planerat :planned, utcheckad :actual).', ['minutes' => -$endDiff, 'planned' => self::time($plan->end), 'actual' => self::time($end)]))
                : new Deviation(Deviation::LATE_FINISH, __('Slutade :minutes min sent (planerat :planned, utcheckad :actual).', ['minutes' => $endDiff, 'planned' => self::time($plan->end), 'actual' => self::time($end)]));
        }

        $gross = intdiv((int) $end?->getTimestamp() - (int) $start?->getTimestamp(), 60);
        $break = $ownBreak ? $worked->breakMinutes : $plan->breakMinutes;

        return [new WorkInterval($start, $end, max(0, min($break, $gross))), $deviations];
    }

    /**
     * Pairs planned shifts with worked intervals: each planned shift, in
     * order, takes the unmatched interval it overlaps most.
     *
     * @param  list<WorkInterval>  $planned
     * @param  list<WorkInterval>  $worked
     * @return array{list<array{WorkInterval, WorkInterval}>, list<WorkInterval>}
     */
    private static function match(array $planned, array $worked): array
    {
        $free = array_values(array_filter($worked, fn (WorkInterval $interval): bool => $interval->start !== null || $interval->end !== null));
        $pairs = [];

        foreach ($planned as $plan) {
            $best = null;
            $bestScore = 0;

            foreach ($free as $index => $candidate) {
                $score = self::score($plan, $candidate);

                if ($score > $bestScore) {
                    [$best, $bestScore] = [$index, $score];
                }
            }

            if ($best !== null) {
                $pairs[] = [$plan, $free[$best]];
                unset($free[$best]);
            }
        }

        return [$pairs, array_values($free)];
    }

    /** How well a worked interval fits a planned shift: seconds of overlap, or 1 when only near it. */
    private static function score(WorkInterval $plan, WorkInterval $worked): int
    {
        $planStart = (int) $plan->start?->getTimestamp();
        $planEnd = (int) $plan->end?->getTimestamp();
        $length = $planEnd - $planStart;
        $start = $worked->start?->getTimestamp() ?? ((int) $worked->end?->getTimestamp() - $length);
        $end = $worked->end?->getTimestamp() ?? ($start + $length);
        $overlap = min($planEnd, $end) - max($planStart, $start);

        if ($overlap > 0) {
            return $overlap;
        }

        return -$overlap <= self::MATCH_WINDOW_MINUTES * 60 ? 1 : 0;
    }

    private static function missing(WorkInterval $worked): Deviation
    {
        return $worked->end === null
            ? Deviation::blocking(Deviation::MISSING_CHECK_OUT, __('Incheckad :time men aldrig utcheckad. Rätta i personalliggaren eller ange tiden under Ändra.', ['time' => self::time($worked->start)]))
            : Deviation::blocking(Deviation::MISSING_CHECK_IN, __('Utcheckad :time utan incheckning. Rätta i personalliggaren eller ange tiden under Ändra.', ['time' => self::time($worked->end)]));
    }

    private static function within(int $difference, int $tolerance): bool
    {
        return $difference === 0 || abs($difference) < $tolerance;
    }

    /** Signed whole minutes from a to b. */
    private static function minutes(?CarbonImmutable $a, ?CarbonImmutable $b): int
    {
        return (int) round(((int) $b?->getTimestamp() - (int) $a?->getTimestamp()) / 60);
    }

    /** To the nearest step of the wall clock in Sweden; 0 or 1 leaves the time as it is (to the minute). */
    private static function round(?CarbonImmutable $moment, int $step): ?CarbonImmutable
    {
        if ($moment === null) {
            return null;
        }

        $local = $moment->setTimezone(self::ZONE)->startOfMinute();

        if ($step <= 1) {
            return $local;
        }

        $minutes = (int) round(($local->hour * 60 + $local->minute) / $step) * $step;

        return $minutes >= 1440
            ? $local->addDay()->startOfDay()
            : $local->setTime(intdiv($minutes, 60), $minutes % 60);
    }

    private static function date(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(self::ZONE)->toDateString();
    }

    private static function time(?CarbonImmutable $moment): string
    {
        return $moment === null ? '–' : $moment->setTimezone(self::ZONE)->format('H:i');
    }
}
