<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Domain\PlannedShift;
use App\Modules\Planning\Domain\WorkingTimeChecks;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What approving a request would mean for the people who get a shift: the
 * working-time checks re-run on their schedule with the change applied, and
 * whether they said the time does not suit them. Dropping a shift only
 * removes work, so it is not checked. Warnings only; they never block. Four
 * queries for any number of requests.
 */
final class RequestChecks
{
    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  Collection<int, ShiftRequest>  $requests  with shift and counterpartShift loaded
     * @return array<int, list<array{message: string, source: string}>> request id => warnings
     */
    public function for(Company $company, Collection $requests): array
    {
        $gains = [];

        foreach ($requests as $request) {
            foreach (self::gains($request) as $gain) {
                $gains[] = [$request->id, ...$gain];
            }
        }

        $result = array_fill_keys($requests->pluck('id')->all(), []);

        if ($gains === []) {
            return $result;
        }

        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $employeeIds = array_values(array_unique(array_map(fn (array $gain): int => $gain[1], $gains)));
        $starts = array_map(fn (array $gain): CarbonImmutable => $gain[2]->starts_at, $gains);
        $first = min($starts)->subWeeks(ScheduleData::CONTEXT_WEEKS);
        $last = max($starts)->addWeek();

        $schedule = ShiftQuery::startingOn($first, $last)
            ->whereIn('employee_id', $employeeIds)
            ->where('status', '<>', Shift::CANCELLED)
            ->get()
            ->groupBy('employee_id');
        $employees = Employee::query()->with('employments')->whereKey($employeeIds)->get()->keyBy('id');
        $availability = Availability::query()->whereIn('employee_id', $employeeIds)->get()->groupBy('employee_id');
        $named = count($employeeIds) > 1 || $requests->contains('kind', ShiftRequest::SWAP);

        foreach ($gains as [$requestId, $employeeId, $gained, $given]) {
            $employee = $employees->get($employeeId);
            $name = $employee instanceof Employee ? "{$employee->first_name} {$employee->last_name}" : '';
            $prefix = $named && $name !== '' ? "{$name}: " : '';

            $shifts = collect($schedule->get($employeeId, []))
                ->reject(fn (Shift $shift): bool => $shift->id === $gained->id || $shift->id === $given?->id)
                ->push($gained);

            $warnings = WorkingTimeChecks::run(
                array_values($shifts->map(fn (Shift $shift): PlannedShift => new PlannedShift($shift->id, $shift->starts_at, $shift->ends_at, $shift->break_minutes))->all()),
                (array) $settings['working_time_rules'],
                PlanningSettings::bool($settings, 'minors_enabled'),
                (array) $settings['minors_rules'],
                $employee?->birth_date,
                $employee?->employments->sortBy('start_date')->last()?->weekly_hours,
                [$gained->id],
            );

            foreach ($warnings as $warning) {
                $result[$requestId][] = ['message' => $prefix.$warning->message, 'source' => $warning->source];
            }

            if (self::isUnavailable($availability->get($employeeId, collect()), $gained)) {
                $result[$requestId][] = ['message' => $prefix.__('Har angett att den tiden inte passar.'), 'source' => ''];
            }
        }

        return $result;
    }

    /**
     * Who gets which shift if the request is approved, and which shift they give away.
     *
     * @return list<array{int, Shift, ?Shift}>
     */
    private static function gains(ShiftRequest $request): array
    {
        $shift = $request->shift;
        $other = $request->counterpartShift;

        return match ($request->kind) {
            ShiftRequest::CLAIM => [[$request->requested_by, $shift, null]],
            ShiftRequest::SWAP => $other instanceof Shift && $request->counterpart_employee_id !== null
                ? [[$request->requested_by, $other, $shift], [$request->counterpart_employee_id, $shift, $other]]
                : [],
            default => [],
        };
    }

    /**
     * Whether the employee said the shift's time does not suit them: an
     * UNAVAILABLE row covers part of the shift's first day. A row for the
     * date decides over the weekly rows, as in the schedule's marks.
     *
     * @param  iterable<Availability>  $rows  the employee's rows, all kinds
     */
    public static function isUnavailable(iterable $rows, Shift $shift): bool
    {
        $day = $shift->starts_at->startOfDay();
        $matching = collect($rows)->filter(fn (Availability $row): bool => $row->appliesTo($day));
        $dated = $matching->whereNotNull('date');

        return ($dated->isNotEmpty() ? $dated : $matching)
            ->contains(fn (Availability $row): bool => $row->kind === Availability::UNAVAILABLE && self::overlaps($row, $shift));
    }

    /** Whether an unavailable row covers part of the shift's first day. */
    public static function overlaps(Availability $row, Shift $shift): bool
    {
        $day = $shift->starts_at->startOfDay();

        if (! $row->appliesTo($day)) {
            return false;
        }

        if ($row->isWholeDay()) {
            return true;
        }

        $from = CarbonImmutable::parse("{$day->toDateString()} {$row->from}", LocalTimestamp::ZONE);
        $to = CarbonImmutable::parse("{$day->toDateString()} {$row->to}", LocalTimestamp::ZONE);

        return $shift->starts_at->lessThan($to) && $shift->ends_at->greaterThan($from);
    }
}
