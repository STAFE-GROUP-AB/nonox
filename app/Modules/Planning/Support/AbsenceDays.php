<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Payroll\Models\Absence;
use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who is absent on which day, for the schedule (ADR-037 amendment, gap 6).
 *
 * The absence type is health data for sick leave, so Planering never asks
 * for it, also not for vacation: the one query here selects employee_id,
 * date_from, date_to, extent_bp and status, and nothing else (no type, note
 * or doctor certificate). All a planner learns is "frånvarande" (approved),
 * "delvis frånvarande" (approved, less than the whole day) and, for holders
 * of planning:manage, "frånvaro begärd" (requested, not yet decided).
 * Nothing here reads a person's own absence for Min sida, and colleagues
 * never get a marker.
 *
 * TODO(legal-verify): whether "frånvarande" derived from a sick report is
 * itself health data (GDPR art. 9) in the planner's view, and the legal
 * basis for showing it to a scheduler without payroll:view-sensitive
 * (proposed: art. 9.2 b with lagen (2018:218) 3 kap. 2 §, limited to
 * "absent").
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
            ->get(['employee_id', 'date_from', 'date_to', 'extent_bp', 'status']);

        $extent = [];
        $requested = [];

        foreach ($rows as $absence) {
            $last = $absence->date_to->min($to);

            for ($day = $absence->date_from->max($from); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
                $date = $day->toDateString();

                if ($absence->status === 'APPROVED') {
                    $extent[$absence->employee_id][$date] = min(self::WHOLE_DAY_BP, ($extent[$absence->employee_id][$date] ?? 0) + (int) $absence->extent_bp);

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

    /**
     * Published shifts of absent people from the start of today, for the
     * to-do list. Three queries.
     *
     * @return Collection<int, Shift>
     */
    public static function upcomingShiftsOfAbsent(CarbonImmutable $today): Collection
    {
        $from = $today->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $last = Absence::query()->where('status', 'APPROVED')->whereDate('date_to', '>=', $from->toDateString())->max('date_to');

        if ($last === null) {
            return collect();
        }

        $to = CarbonImmutable::parse((string) $last, LocalTimestamp::ZONE)->startOfDay();
        $shifts = ShiftQuery::startingOn($from, $to)
            ->where('status', Shift::PUBLISHED)
            ->whereNotNull('employee_id')
            ->orderBy('starts_at')
            ->get(['id', 'employee_id', 'starts_at', 'ends_at']);
        $absent = self::shiftsOfAbsent($shifts);

        return $shifts->filter(fn (Shift $shift): bool => isset($absent[(string) $shift->id]))->values();
    }
}
