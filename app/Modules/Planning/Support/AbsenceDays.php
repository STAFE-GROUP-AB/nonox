<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Payroll\Models\Absence;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who is absent on which day, for the schedule (ADR-037 amendment, gap 6).
 *
 * The absence type is health data for sick leave, so Planering never asks
 * for it, also not for vacation: the one query here selects employee_id,
 * date_from, date_to, extent_bp, hours (the scheduled hours missed per day)
 * and status, and nothing else (no type, note or doctor certificate). Part
 * of a day is the extent when payroll has one below 100 %, else the hours
 * against the employment's hours per working day (weekly hours over five, or
 * 8: the same default as payroll's absence approval). All a planner learns is "frånvarande" (approved),
 * "delvis frånvarande" (approved, less than the whole day) and, for holders
 * of planning:manage, "frånvaro begärd" (requested, not yet decided).
 * Nothing here reads a person's own absence for Min sida, and colleagues
 * never get a marker.
 *
 * TODO(legal-verify): whether "frånvarande" derived from a sick or VAB
 * report is itself health data (GDPR art. 9) in the planner's view; whether
 * "Frånvaro begärd" (employees' own sick and VAB requests, up to seven days
 * ahead) and "Delvis frånvarande" are, and whether a pattern of such marks
 * lets a scheduler infer a cause; and the legal basis for showing any of
 * them to a scheduler without payroll:view-sensitive (proposed: art. 9.2 b
 * with dataskyddslagen (2018:218) 3 kap. 2 §, limited to "absent").
 */
final class AbsenceDays
{
    public const string FULL = 'FULL';

    public const string PARTIAL = 'PARTIAL';

    public const string REQUESTED = 'REQUESTED';

    private const int WHOLE_DAY_BP = 10000;

    public static function warning(): string
    {
        return __('Personen är frånvarande den här dagen.');
    }

    /**
     * The marks per employee and date. One query. Requested absence is only
     * read when the viewer may manage the schedule.
     *
     * @return array<int, array<string, string>> employee id => date => FULL | PARTIAL | REQUESTED
     */
    public static function between(CarbonImmutable $from, CarbonImmutable $to, bool $withRequested = false): array
    {
        $from = $from->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $to = $to->setTimezone(LocalTimestamp::ZONE)->startOfDay();

        $rows = Absence::query()
            ->whereIn('status', $withRequested ? ['APPROVED', 'REQUESTED'] : ['APPROVED'])
            ->whereDate('date_from', '<=', $to->toDateString())
            ->whereDate('date_to', '>=', $from->toDateString())
            ->get(['employee_id', 'date_from', 'date_to', 'extent_bp', 'hours', 'status']);

        $daily = self::dailyHours($rows, $from, $to);
        $extent = [];
        $requested = [];

        foreach ($rows as $absence) {
            $last = $absence->date_to->min($to);

            for ($day = $absence->date_from->max($from); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
                $date = $day->toDateString();

                if ($absence->status === 'APPROVED') {
                    $extent[$absence->employee_id][$date] = min(self::WHOLE_DAY_BP, ($extent[$absence->employee_id][$date] ?? 0) + self::shareOfDay($absence, $daily[$absence->employee_id] ?? 8.0));

                    continue;
                }

                $requested[$absence->employee_id][$date] = true;
            }
        }

        $marks = [];

        foreach ($requested as $employeeId => $dates) {
            foreach (array_keys($dates) as $date) {
                $marks[$employeeId][$date] = self::REQUESTED;
            }
        }

        foreach ($extent as $employeeId => $dates) {
            foreach ($dates as $date => $bp) {
                $marks[$employeeId][$date] = $bp >= self::WHOLE_DAY_BP ? self::FULL : self::PARTIAL;
            }
        }

        return $marks;
    }

    /** The part of a working day an absence covers, in basis points: the extent below 100 %, else the hours missed against the day's hours. */
    private static function shareOfDay(Absence $absence, float $dailyHours): int
    {
        $extent = (int) $absence->extent_bp;
        $hours = (float) str_replace(',', '.', (string) $absence->hours);

        if ($extent > 0 && $extent < self::WHOLE_DAY_BP) {
            return $extent;
        }

        if ($hours > 0 && $dailyHours > 0 && $hours < $dailyHours - 0.01) {
            return (int) round($hours / $dailyHours * self::WHOLE_DAY_BP);
        }

        return self::WHOLE_DAY_BP;
    }

    /**
     * Each person's hours per working day (weekly hours over five, else 8),
     * read only for people with an approved absence that states hours. One
     * query, or none.
     *
     * @param  Collection<int, Absence>  $rows
     * @return array<int, float>
     */
    private static function dailyHours(Collection $rows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $rows->where('status', 'APPROVED')->filter(fn (Absence $absence): bool => (float) $absence->hours > 0)->pluck('employee_id')->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $daily = [];
        $employments = Employment::query()
            ->whereIn('employee_id', $ids)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()))
            ->orderBy('start_date')
            ->get(['employee_id', 'weekly_hours']);

        foreach ($employments as $employment) {
            $weekly = (float) $employment->weekly_hours;
            $daily[$employment->employee_id] = $weekly > 0 ? $weekly / 5 : 8.0;
        }

        return $daily;
    }

    /**
     * Whether the employee has approved absence, of any extent, on a day the
     * shift touches.
     *
     * @param  array<int, array<string, string>>  $marks
     */
    public static function absentDuring(array $marks, ?int $employeeId, Shift $shift): bool
    {
        if ($employeeId === null) {
            return false;
        }

        foreach (self::days($shift) as $date) {
            $mark = $marks[$employeeId][$date] ?? null;

            if ($mark === self::FULL || $mark === self::PARTIAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shifts, by id, of people who are absent on a day the shift touches.
     * One query for any number of shifts.
     *
     * @param  iterable<Shift>  $shifts
     * @return array<string, true>
     */
    public static function shiftsOfAbsent(iterable $shifts): array
    {
        $list = collect($shifts)->filter(fn (Shift $shift): bool => $shift->employee_id !== null)->values();

        if ($list->isEmpty()) {
            return [];
        }

        $first = $list->sortBy(fn (Shift $shift): int => $shift->starts_at->getTimestamp())->firstOrFail();
        $last = $list->sortByDesc(fn (Shift $shift): int => $shift->ends_at->getTimestamp())->firstOrFail();
        $marks = self::between($first->starts_at, $last->ends_at);
        $found = [];

        foreach ($list as $shift) {
            if (self::absentDuring($marks, $shift->employee_id, $shift)) {
                $found[(string) $shift->id] = true;
            }
        }

        return $found;
    }

    /**
     * The dates a shift touches in Sweden: its first day, and the next one
     * when it runs past midnight.
     *
     * @return list<string>
     */
    public static function days(Shift $shift): array
    {
        $start = $shift->starts_at->setTimezone(LocalTimestamp::ZONE);
        $end = $shift->ends_at->setTimezone(LocalTimestamp::ZONE);
        $dates = [$start->toDateString()];

        for ($day = $start->startOfDay()->addDay(); $day->lessThan($end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** How far ahead the to-do list looks for shifts of absent people. */
    public const int HORIZON_WEEKS = 8;

    /**
     * Published shifts of absent people from the start of today, up to
     * HORIZON_WEEKS ahead, for the to-do list. Only the shifts of people with
     * approved absence in that window are read. Three queries (four with a
     * part-day absence).
     *
     * @return Collection<int, Shift>
     */
    public static function upcomingShiftsOfAbsent(CarbonImmutable $today): Collection
    {
        $from = $today->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $horizon = $from->addWeeks(self::HORIZON_WEEKS);
        $absences = Absence::query()
            ->where('status', 'APPROVED')
            ->whereDate('date_to', '>=', $from->toDateString())
            ->whereDate('date_from', '<=', $horizon->toDateString())
            ->get(['employee_id', 'date_to']);

        if ($absences->isEmpty()) {
            return collect();
        }

        $to = $absences->max('date_to')->min($horizon);
        $shifts = ShiftQuery::startingOn($from, $to)
            ->where('status', Shift::PUBLISHED)
            ->whereIn('employee_id', $absences->pluck('employee_id')->unique()->values()->all())
            ->orderBy('starts_at')
            ->get(['id', 'employee_id', 'starts_at', 'ends_at']);
        $absent = self::shiftsOfAbsent($shifts);

        return $shifts->filter(fn (Shift $shift): bool => isset($absent[(string) $shift->id]))->values();
    }
}
