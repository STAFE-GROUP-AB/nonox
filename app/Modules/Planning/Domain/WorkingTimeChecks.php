<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use Carbon\CarbonImmutable;

/**
 * Runs a company's working-time rule set (PlanningSettings) over one
 * employee's planned shifts and returns warnings with the rule's source
 * (PLANNING_MODULE.md §5). The rules are data, unverified until a named
 * person has checked them, and the checks only warn.
 *
 * Simplifications, each a TODO(legal-verify) on the rule itself: daily rest
 * is the gap between two shifts, weekly rest and weekly hours use the
 * calendar week (Monday 00:00), the four-week overtime cap counts hours over
 * the ordinary week in the four calendar weeks ending with the shift's week,
 * and the yearly caps are not checked on a plan (they need worked time). The
 * position of a break is not planned, so a long shift with any break passes
 * the break check. Pure.
 */
final class WorkingTimeChecks
{
    /**
     * @param  list<PlannedShift>  $shifts  the employee's shifts, including the weeks before for context
     * @param  array<string, array<string, mixed>>  $rules  resolved working_time_rules
     * @param  array<string, array<string, mixed>>  $minorsRules  resolved minors_rules
     * @param  ?list<string>  $only  report warnings only for these shift ids (the visible period)
     * @return list<WorkingTimeWarning>
     */
    public static function run(
        array $shifts,
        array $rules,
        bool $minorsEnabled = false,
        array $minorsRules = [],
        ?CarbonImmutable $birthDate = null,
        ?string $weeklyHours = null,
        ?array $only = null,
    ): array {
        usort($shifts, fn (PlannedShift $a, PlannedShift $b): int => $a->start <=> $b->start);
        $warnings = [];
        $minorBand = $minorsEnabled && $birthDate !== null ? self::minorBand($birthDate, $shifts) : [];

        array_push($warnings, ...self::rest($shifts, $rules, $minorsRules, $minorBand));
        array_push($warnings, ...self::breaks($shifts, $rules));
        array_push($warnings, ...self::weeks($shifts, $rules, $weeklyHours));

        if ($minorBand !== []) {
            array_push($warnings, ...self::minors($shifts, $minorsRules, $minorBand));
        }

        if ($only !== null) {
            $warnings = array_values(array_filter($warnings, fn (WorkingTimeWarning $warning): bool => in_array($warning->shiftId, $only, true)));
        }

        return $warnings;
    }

    /**
     * Daily rest between consecutive shifts, and shifts that overlap.
     *
     * @param  list<PlannedShift>  $shifts
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, array<string, mixed>>  $minorsRules
     * @param  array<string, string>  $minorBand  shift id => '16' | '18'
     * @return list<WorkingTimeWarning>
     */
    private static function rest(array $shifts, array $rules, array $minorsRules, array $minorBand): array
    {
        $warnings = [];

        for ($i = 1; $i < count($shifts); $i++) {
            $previous = $shifts[$i - 1];
            $shift = $shifts[$i];
            $gap = intdiv($shift->start->getTimestamp() - $previous->end->getTimestamp(), 60);

            if ($gap < 0) {
                $warnings[] = new WorkingTimeWarning($shift->id, 'overlap', __('Passet överlappar ett annat pass för samma person.'), '');

                continue;
            }

            $rule = isset($minorBand[$shift->id]) && self::enabled($minorsRules, 'under_18_daily_rest')
                ? ['key' => 'under_18_daily_rest', ...$minorsRules['under_18_daily_rest']]
                : (self::enabled($rules, 'daily_rest') ? ['key' => 'daily_rest', ...$rules['daily_rest']] : null);

            if ($rule !== null && $gap < (int) $rule['value']) {
                $warnings[] = new WorkingTimeWarning($shift->id, (string) $rule['key'], __('Bara :rest vila före passet. Dygnsvilan ska vara minst :minimum.', [
                    'rest' => self::hours($gap),
                    'minimum' => self::hours((int) $rule['value']),
                ]), (string) $rule['source']);
            }
        }

        return $warnings;
    }

    /**
     * @param  list<PlannedShift>  $shifts
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<WorkingTimeWarning>
     */
    private static function breaks(array $shifts, array $rules): array
    {
        if (! self::enabled($rules, 'max_without_break')) {
            return [];
        }

        $limit = (int) $rules['max_without_break']['value'];
        $warnings = [];

        foreach ($shifts as $shift) {
            if ($shift->breakMinutes === 0 && $shift->grossMinutes() > $limit) {
                $warnings[] = new WorkingTimeWarning($shift->id, 'max_without_break', __('Passet är :length utan rast. Rast behövs efter högst :limit i följd.', [
                    'length' => self::hours($shift->grossMinutes()),
                    'limit' => self::hours($limit),
                ]), (string) $rules['max_without_break']['source']);
            }
        }

        return $warnings;
    }

    /**
     * Weekly rest, weekly hours, extra time over the contracted week, and the
     * four-week overtime cap, per calendar week.
     *
     * @param  list<PlannedShift>  $shifts
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<WorkingTimeWarning>
     */
    private static function weeks(array $shifts, array $rules, ?string $weeklyHours): array
    {
        /** @var array<string, list<PlannedShift>> $byWeek */
        $byWeek = [];

        foreach ($shifts as $shift) {
            $byWeek[$shift->start->startOfWeek(CarbonImmutable::MONDAY)->toDateString()][] = $shift;
        }

        $warnings = [];
        $ordinary = self::enabled($rules, 'ordinary_week') ? (int) $rules['ordinary_week']['value'] * 60 : null;
        $worked = array_map(fn (array $week): int => array_sum(array_map(fn (PlannedShift $shift): int => $shift->workedMinutes(), $week)), $byWeek);

        foreach ($byWeek as $weekStart => $week) {
            $first = $week[0];
            $last = $week[count($week) - 1];
            $start = CarbonImmutable::parse($weekStart, $first->start->getTimezone())->startOfDay();
            $minutes = $worked[$weekStart];

            if (self::enabled($rules, 'weekly_rest')) {
                $longest = self::longestGap($shifts, $start, $start->addWeek());

                if ($longest < (int) $rules['weekly_rest']['value']) {
                    $warnings[] = new WorkingTimeWarning($first->id, 'weekly_rest', __('Längsta sammanhängande ledighet vecka :week är :rest. Veckovilan ska vara minst :minimum.', [
                        'week' => (int) $start->format('W'),
                        'rest' => self::hours($longest),
                        'minimum' => self::hours((int) $rules['weekly_rest']['value']),
                    ]), (string) $rules['weekly_rest']['source']);
                }
            }

            if (self::enabled($rules, 'total_week') && $minutes > (int) $rules['total_week']['value'] * 60) {
                $warnings[] = new WorkingTimeWarning($last->id, 'total_week', __(':hours planerat vecka :week, mer än :limit sammanlagd arbetstid.', [
                    'hours' => self::hours($minutes), 'week' => (int) $start->format('W'), 'limit' => self::hours((int) $rules['total_week']['value'] * 60),
                ]), (string) $rules['total_week']['source']);
            } elseif ($ordinary !== null && $minutes > $ordinary) {
                $warnings[] = new WorkingTimeWarning($last->id, 'ordinary_week', __(':hours planerat vecka :week, mer än :limit ordinarie arbetstid. Över tid kan det bli övertid om snittet inte jämnas ut.', [
                    'hours' => self::hours($minutes), 'week' => (int) $start->format('W'), 'limit' => self::hours($ordinary),
                ]), (string) $rules['ordinary_week']['source']);
            }

            if (self::enabled($rules, 'extra_time_week') && is_numeric($weeklyHours) && $ordinary !== null) {
                $contracted = (int) round((float) $weeklyHours * 60);

                if ($contracted < $ordinary && $minutes > $contracted) {
                    $warnings[] = new WorkingTimeWarning($last->id, 'extra_time_week', __('Mertid: :extra över den avtalade veckoarbetstiden (:contracted) vecka :week.', [
                        'extra' => self::hours(min($minutes, $ordinary) - $contracted), 'contracted' => self::hours($contracted), 'week' => (int) $start->format('W'),
                    ]), (string) $rules['extra_time_week']['source']);
                }
            }

            if ($ordinary !== null && self::enabled($rules, 'overtime_four_weeks')) {
                $overtime = 0;

                for ($back = 0; $back < 4; $back++) {
                    $overtime += max(0, ($worked[$start->subWeeks($back)->toDateString()] ?? 0) - $ordinary);
                }

                if ($overtime > (int) $rules['overtime_four_weeks']['value'] * 60) {
                    $warnings[] = new WorkingTimeWarning($last->id, 'overtime_four_weeks', __(':hours över ordinarie arbetstid på fyra veckor till och med vecka :week. Allmän övertid är högst :limit.', [
                        'hours' => self::hours($overtime), 'week' => (int) $start->format('W'), 'limit' => self::hours((int) $rules['overtime_four_weeks']['value'] * 60),
                    ]), (string) $rules['overtime_four_weeks']['source']);
                }
            }
        }

        return $warnings;
    }

    /**
     * Minors: hours per day and week and night work.
     *
     * @param  list<PlannedShift>  $shifts
     * @param  array<string, array<string, mixed>>  $rules
     * @param  array<string, string>  $band
     * @return list<WorkingTimeWarning>
     */
    private static function minors(array $shifts, array $rules, array $band): array
    {
        $warnings = [];
        $perDay = [];
        $perWeek = [];

        foreach ($shifts as $shift) {
            if (! isset($band[$shift->id])) {
                continue;
            }

            $prefix = $band[$shift->id] === '16' ? 'under_16' : 'under_18';
            $day = $shift->start->toDateString();
            $week = $shift->start->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $perDay[$day] = ($perDay[$day] ?? 0) + $shift->workedMinutes();
            $perWeek[$week] = ($perWeek[$week] ?? 0) + $shift->workedMinutes();

            if (self::enabled($rules, "{$prefix}_max_day") && $perDay[$day] > (int) $rules["{$prefix}_max_day"]['value']) {
                $warnings[] = new WorkingTimeWarning($shift->id, "{$prefix}_max_day", __('Minderårig: :hours planerat :date, mer än :limit per dag.', [
                    'hours' => self::hours($perDay[$day]), 'date' => $day, 'limit' => self::hours((int) $rules["{$prefix}_max_day"]['value']),
                ]), (string) $rules["{$prefix}_max_day"]['source']);
            }

            if (self::enabled($rules, "{$prefix}_max_week") && $perWeek[$week] > (int) $rules["{$prefix}_max_week"]['value'] * 60) {
                $warnings[] = new WorkingTimeWarning($shift->id, "{$prefix}_max_week", __('Minderårig: :hours planerat vecka :week, mer än :limit per vecka.', [
                    'hours' => self::hours($perWeek[$week]), 'week' => (int) $shift->start->format('W'), 'limit' => self::hours((int) $rules["{$prefix}_max_week"]['value'] * 60),
                ]), (string) $rules["{$prefix}_max_week"]['source']);
            }

            if (self::enabled($rules, "{$prefix}_night_from") && self::enabled($rules, "{$prefix}_night_to")
                && self::overlapsNight($shift, (string) $rules["{$prefix}_night_from"]['value'], (string) $rules["{$prefix}_night_to"]['value'])) {
                $warnings[] = new WorkingTimeWarning($shift->id, "{$prefix}_night", __('Minderårig: passet ligger mellan :from och :to.', [
                    'from' => $rules["{$prefix}_night_from"]['value'], 'to' => $rules["{$prefix}_night_to"]['value'],
                ]), (string) $rules["{$prefix}_night_from"]['source']);
            }
        }

        return $warnings;
    }

    /**
     * Which shifts are worked by a minor: '16' under 16, '18' under 18, on
     * the day the shift starts.
     *
     * @param  list<PlannedShift>  $shifts
     * @return array<string, string>
     */
    private static function minorBand(CarbonImmutable $birthDate, array $shifts): array
    {
        $band = [];

        foreach ($shifts as $shift) {
            $day = $shift->start->startOfDay();

            if ($day->lessThan($birthDate->addYears(16))) {
                $band[$shift->id] = '16';
            } elseif ($day->lessThan($birthDate->addYears(18))) {
                $band[$shift->id] = '18';
            }
        }

        return $band;
    }

    private static function overlapsNight(PlannedShift $shift, string $from, string $to): bool
    {
        [$fromHour, $fromMinute] = array_map('intval', explode(':', $from));
        [$toHour, $toMinute] = array_map('intval', explode(':', $to));

        for ($day = $shift->start->startOfDay()->subDay(); $day->lessThan($shift->end); $day = $day->addDay()) {
            $start = $day->setTime($fromHour, $fromMinute);
            $end = ($toHour * 60 + $toMinute <= $fromHour * 60 + $fromMinute ? $day->addDay() : $day)->setTime($toHour, $toMinute);

            if ($shift->start->lessThan($end) && $shift->end->greaterThan($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The longest stretch without work inside [from, to), in minutes.
     *
     * @param  list<PlannedShift>  $shifts  sorted by start
     */
    private static function longestGap(array $shifts, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $cursor = $from->getTimestamp();
        $longest = 0;

        foreach ($shifts as $shift) {
            if ($shift->end->getTimestamp() <= $from->getTimestamp() || $shift->start->getTimestamp() >= $to->getTimestamp()) {
                continue;
            }

            $longest = max($longest, $shift->start->getTimestamp() - $cursor);
            $cursor = max($cursor, $shift->end->getTimestamp());
        }

        return intdiv(max($longest, $to->getTimestamp() - $cursor), 60);
    }

    /** @param  array<string, array<string, mixed>>  $rules */
    private static function enabled(array $rules, string $key): bool
    {
        return isset($rules[$key]) && ($rules[$key]['enabled'] ?? false) === true && isset($rules[$key]['value']);
    }

    /** Minutes as hours in Swedish: "10 h" or "10,5 h". */
    public static function hours(int $minutes): string
    {
        $hours = $minutes / 60;

        return (floor($hours) === $hours ? (string) (int) $hours : str_replace('.', ',', (string) round($hours, 2))).' h';
    }
}
