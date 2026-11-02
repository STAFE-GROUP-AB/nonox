<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Planning\Domain\PlannedShift;
use App\Modules\Planning\Domain\WorkingTimeChecks;
use App\Modules\Planning\Domain\WorkingTimeWarning;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\Skill;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\StaffLedgerModule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Everything the schedule shows for a period, in a fixed number of queries
 * whatever the number of people and shifts (PLANNING_MODULE.md §1.5): the
 * people employed in the period, areas, skills, the shifts (with three weeks
 * before for the working-time checks), and the sales in the books for the
 * period and the four weeks before it. Costs are the estimates stored on
 * the shifts; the warnings are computed here, in memory.
 */
final class ScheduleData
{
    public const int CONTEXT_WEEKS = 3;

    public const int REFERENCE_WEEKS = 4;

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  array{area?: ?int, skill?: ?int, location?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function load(Company $company, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = $day;
        }

        $areas = Area::query()->orderBy('sort')->orderBy('name')->get();
        $skills = Skill::query()->orderBy('name')->get();
        $employeeSkills = EmployeeSkill::query()->get(['employee_id', 'skill_id'])->groupBy('employee_id')->map(fn (Collection $rows): array => $rows->pluck('skill_id')->all());
        $locations = $this->modules->isEnabled(StaffLedgerModule::KEY, $company) ? Location::query()->whereNull('archived_at')->orderBy('name')->get(['id', 'name']) : collect();

        $employees = Employee::query()
            ->where('status', '<>', 'TERMINATED')
            ->whereHas('employments', fn ($query) => self::overlapping($query, $from, $to))
            ->with(['employments' => fn ($query) => self::overlapping($query, $from, $to)->orderBy('start_date')])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $all = ShiftQuery::startingOn($from->subWeeks(self::CONTEXT_WEEKS), $to)
            ->where('status', '<>', Shift::CANCELLED)
            ->orderBy('starts_at')
            ->get();

        $visible = $all->filter(fn (Shift $shift): bool => $shift->starts_at->greaterThanOrEqualTo($from) && $shift->starts_at->lessThan($to->addDay()));
        $areaFilter = $filters['area'] ?? null;
        $skillFilter = $filters['skill'] ?? null;
        $locationFilter = $filters['location'] ?? null;
        $areaIds = $locationFilter === null ? null : $areas->where('location_id', $locationFilter)->pluck('id')->all();

        $shown = $visible->filter(fn (Shift $shift): bool => ($areaFilter === null || $shift->area_id === $areaFilter)
            && ($skillFilter === null || $shift->skill_id === $skillFilter)
            && ($areaIds === null || in_array($shift->area_id, $areaIds, true)));

        $people = $employees->filter(fn (Employee $employee): bool => $skillFilter === null || in_array($skillFilter, $employeeSkills->get($employee->id, []), true));

        if ($areaFilter !== null || $areaIds !== null) {
            $withShifts = $shown->pluck('employee_id')->filter()->unique()->all();
            $people = $people->filter(fn (Employee $employee): bool => in_array($employee->id, $withShifts, true));
        }

        $availability = $this->availabilityRows($days);
        $warnings = $this->warnings($all, $visible, $employees, $settings);

        // Unavailability is a warning whatever the self-service setting: the employee did say so.
        foreach ($visible->whereNotNull('employee_id') as $shift) {
            if (RequestChecks::isUnavailable($availability->get($shift->employee_id, collect()), $shift)) {
                $warnings[$shift->id][] = new WorkingTimeWarning($shift->id, 'unavailable', __('Har angett att den tiden inte passar.'), '');
            }
        }
        $sales = SalesFromBooks::perDay($from->subWeeks(self::REFERENCE_WEEKS), $to, PlanningSettings::int($settings, 'sales_account_from'), PlanningSettings::int($settings, 'sales_account_to'));

        return [
            'settings' => $settings,
            'days' => $days,
            'areas' => $areas,
            'skills' => $skills,
            'locations' => $locations,
            'people' => $people->values(),
            'employeeSkills' => $employeeSkills,
            'shifts' => $shown->values(),
            'warnings' => $warnings,
            'totals' => $this->totals($days, $shown, $sales),
            'drafts' => $visible->where('status', Shift::DRAFT)->count(),
            'unknownEmployees' => $shown->pluck('employee_id')->filter()->unique()->diff($employees->pluck('id'))->values()->all(),
            'availability' => PlanningSettings::bool($settings, 'allow_availability') ? $this->availability($days, $availability) : [],
        ];
    }

    /**
     * @param  Builder<Employment>|HasMany<Employment, Employee>  $query
     * @return Builder<Employment>|HasMany<Employment, Employee>
     */
    private static function overlapping($query, CarbonImmutable $from, CarbonImmutable $to)
    {
        return $query->whereDate('start_date', '<=', $to->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString()));
    }

    /**
     * The working-time warnings of the visible shifts, by shift id.
     *
     * @param  Collection<int, Shift>  $all
     * @param  Collection<int, Shift>  $visible
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $settings
     * @return array<string, list<WorkingTimeWarning>>
     */
    private function warnings(Collection $all, Collection $visible, Collection $employees, array $settings): array
    {
        $byId = $employees->keyBy('id');
        $warnings = [];

        foreach ($all->whereNotNull('employee_id')->groupBy('employee_id') as $employeeId => $shifts) {
            $employee = $byId->get($employeeId);
            $only = array_values(array_map('strval', $visible->where('employee_id', $employeeId)->pluck('id')->all()));

            if ($only === []) {
                continue;
            }

            $found = WorkingTimeChecks::run(
                array_values($shifts->map(fn (Shift $shift): PlannedShift => new PlannedShift($shift->id, $shift->starts_at, $shift->ends_at, $shift->break_minutes))->all()),
                (array) $settings['working_time_rules'],
                PlanningSettings::bool($settings, 'minors_enabled'),
                (array) $settings['minors_rules'],
                $employee?->birth_date,
                $employee?->employments->last()?->weekly_hours,
                $only,
            );

            foreach ($found as $warning) {
                $warnings[$warning->shiftId][] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * The availability rows that can touch the days: weekly rows and rows
     * for one of the days, by employee. One query.
     *
     * @param  list<CarbonImmutable>  $days
     * @return Collection<int|string, EloquentCollection<int, Availability>>
     */
    private function availabilityRows(array $days): Collection
    {
        if ($days === []) {
            return collect();
        }

        return Availability::query()
            ->where(fn ($query) => $query->whereNotNull('weekday')->orWhereBetween('date', [$days[0]->toDateString(), $days[count($days) - 1]->toDateString()]))
            ->orderBy('from')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * What staff said about their days (Min sida), per person and day: the
     * strongest kind (cannot, would rather, can; a row for the date before a
     * weekly one) and every row as text. Read-only.
     *
     * @param  list<CarbonImmutable>  $days
     * @param  Collection<int|string, EloquentCollection<int, Availability>>  $rows  by employee
     * @return array<int, array<string, array{kind: string, text: string}>>
     */
    private function availability(array $days, Collection $rows): array
    {
        $rank = [Availability::UNAVAILABLE => 3, Availability::PREFERRED => 2, Availability::AVAILABLE => 1];
        $result = [];

        foreach ($rows as $employeeId => $employeeRows) {
            foreach ($days as $day) {
                $matching = $employeeRows->filter(fn (Availability $row): bool => $row->appliesTo($day));
                $dated = $matching->whereNotNull('date');
                $deciding = $dated->isNotEmpty() ? $dated : $matching;

                if ($deciding->isEmpty()) {
                    continue;
                }

                $result[(int) $employeeId][$day->toDateString()] = [
                    'kind' => (string) $deciding->sortByDesc(fn (Availability $row): int => $rank[$row->kind] ?? 0)->first()?->kind,
                    'text' => $deciding->map(fn (Availability $row): string => trim(Availability::kindLabel($row->kind).' '.mb_strtolower($row->timeLabel()).($row->note ? " ({$row->note})" : '')))->join("\n"),
                ];
            }
        }

        return $result;
    }

    /**
     * Per day: planned hours, the estimated cost (and whether part of it is
     * missing), the sales booked that day, the average of the same weekday
     * in the four weeks before, and the labour cost as a share of sales.
     *
     * @param  list<CarbonImmutable>  $days
     * @param  Collection<int, Shift>  $shifts
     * @param  array<string, int>  $sales
     * @return array<string, array{minutes: int, cost: int, incomplete: bool, sales: ?int, reference: ?int, percent: ?float}>
     */
    private function totals(array $days, Collection $shifts, array $sales): array
    {
        $totals = [];
        $byDay = $shifts->groupBy(fn (Shift $shift): string => $shift->starts_at->toDateString());

        foreach ($days as $day) {
            $date = $day->toDateString();
            $dayShifts = $byDay->get($date, collect());
            $cost = (int) $dayShifts->sum(fn (Shift $shift): int => $shift->cost_estimate_ore ?? 0);
            $incomplete = $dayShifts->contains(fn (Shift $shift): bool => $shift->employee_id !== null && ($shift->cost_estimate_ore === null || ((array) ($shift->cost_details['missing'] ?? [])) !== []));
            $references = array_values(array_filter(array_map(fn (int $back): ?int => $sales[$day->subWeeks($back)->toDateString()] ?? null, range(1, self::REFERENCE_WEEKS)), fn (?int $value): bool => $value !== null));
            $reference = $references === [] ? null : intdiv(array_sum($references), count($references));
            $actual = $sales[$date] ?? null;
            $base = $actual ?? $reference;

            $totals[$date] = [
                'minutes' => (int) $dayShifts->sum(fn (Shift $shift): int => $shift->workedMinutes()),
                'cost' => $cost,
                'incomplete' => $incomplete,
                'sales' => $actual,
                'reference' => $reference,
                'percent' => $base !== null && $base > 0 && $cost > 0 ? round($cost * 100 / $base, 1) : null,
            ];
        }

        return $totals;
    }
}
