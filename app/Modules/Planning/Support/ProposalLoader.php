<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Planning\Domain\Proposal\CandidateShift;
use App\Modules\Planning\Domain\Proposal\ProposalCandidate;
use App\Modules\Planning\Domain\Proposal\ProposalInput;
use App\Modules\Planning\Domain\Proposal\ProposalSlot;
use App\Modules\Planning\Domain\Proposal\ProposalWeights;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Loads what the proposal engine needs for one company and period, in a
 * fixed number of queries whatever the number of employees and shifts
 * (ADR-037 amendment, principle 6): the settings, the open shifts of the
 * period that have not started, the employees employed in the period with
 * their employments, skills, availability, approved absence (AbsenceDays:
 * no type or note), and their shifts from four weeks before the fairness
 * window to four weeks after the period. No wage is read.
 */
final class ProposalLoader
{
    /** Weeks of shifts read around the window and the period, for the four-week rules. */
    public const int CONTEXT_WEEKS = 4;

    public function __construct(private readonly ModuleManager $modules) {}

    /** @param  ?ProposalWeights  $weights  weights to use instead of the stored ones */
    public function load(Company $company, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now, ?ProposalWeights $weights = null, ?int $fairnessWeeks = null): ProposalInput
    {
        $settings = PlanningSettings::resolve($this->modules->settings(PlanningModule::KEY, $company));
        $fairnessWeeks ??= PlanningSettings::fairnessWeeks($settings);
        $from = $from->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $to = $to->setTimezone(LocalTimestamp::ZONE)->startOfDay();

        $open = ShiftQuery::startingOn($from, $to)
            ->whereNull('employee_id')
            ->where('status', '<>', Shift::CANCELLED)
            ->where('starts_at', '>', ShiftQuery::instant($now))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'starts_at', 'ends_at', 'break_minutes', 'area_id', 'skill_id']);

        $employees = Employee::query()
            ->where('status', '<>', 'TERMINATED')
            ->whereHas('employments', fn ($query) => self::overlapping($query, $from, $to))
            ->with(['employments' => fn ($query) => $query->orderBy('start_date')])
            ->orderBy('id')
            ->get(['id', 'birth_date']);
        $ids = $employees->pluck('id')->all();

        $skills = EmployeeSkill::query()->whereIn('employee_id', $ids)->get(['employee_id', 'skill_id'])->groupBy('employee_id');
        $availability = Availability::query()
            ->whereIn('employee_id', $ids)
            ->where(fn ($query) => $query->whereNotNull('weekday')->orWhereBetween('date', [$from->toDateString(), $to->addDay()->toDateString()]))
            ->get()
            ->groupBy('employee_id');
        // One day more than the period: a night shift on the last day runs into the next.
        $absence = AbsenceDays::between($from, $to->addDay());
        $shifts = ShiftQuery::startingOn($from->subWeeks($fairnessWeeks + self::CONTEXT_WEEKS), $to->addWeeks(self::CONTEXT_WEEKS))
            ->whereIn('employee_id', $ids)
            ->where('status', '<>', Shift::CANCELLED)
            ->get(['id', 'employee_id', 'starts_at', 'ends_at', 'break_minutes', 'area_id'])
            ->groupBy('employee_id');

        $slots = $open->map(fn (Shift $shift): ProposalSlot => new ProposalSlot(
            id: (string) $shift->id,
            start: $shift->starts_at,
            end: $shift->ends_at,
            breakMinutes: $shift->break_minutes,
            areaId: $shift->area_id,
            skillId: $shift->skill_id,
            unavailable: $this->matching($availability, $shift, fn (EloquentCollection $rows): bool => RequestChecks::isUnavailable($rows, $shift)),
            absent: array_values(array_filter($ids, fn (int $id): bool => isset($absence[$id]) && AbsenceDays::absentDuring($absence, $id, $shift))),
            preferred: $this->matching($availability, $shift, fn (EloquentCollection $rows): bool => self::prefers($rows, $shift)),
        ))->all();

        $candidates = $employees->map(fn (Employee $employee): ProposalCandidate => new ProposalCandidate(
            employeeId: $employee->id,
            skillIds: array_values(array_map('intval', $skills->get($employee->id, collect())->pluck('skill_id')->all())),
            birthDate: $employee->birth_date,
            weeklyHours: $employee->employments->filter(fn (Employment $employment): bool => $employment->start_date->lessThanOrEqualTo($to))->last()?->weekly_hours,
            employments: array_values($employee->employments->map(fn (Employment $employment): array => [$employment->start_date, $employment->end_date])->all()),
            shifts: array_values($shifts->get($employee->id, collect())->map(fn (Shift $shift): CandidateShift => new CandidateShift((string) $shift->id, $shift->starts_at, $shift->ends_at, $shift->break_minutes, $shift->area_id))->all()),
        ))->all();

        return new ProposalInput(
            slots: array_values($slots),
            candidates: array_values($candidates),
            periodFrom: $from,
            periodTo: $to,
            rules: (array) $settings['working_time_rules'],
            minorsEnabled: PlanningSettings::bool($settings, 'minors_enabled'),
            minorsRules: (array) $settings['minors_rules'],
            weights: $weights ?? ProposalWeights::from($settings['proposal_weights'] ?? null),
            fairnessWeeks: $fairnessWeeks,
            unsocialFrom: PlanningSettings::time($settings, 'unsocial_from'),
            unsocialTo: PlanningSettings::time($settings, 'unsocial_to'),
        );
    }

    /**
     * The employees whose availability rows make the test true for the shift.
     *
     * @param  Collection<int|string, EloquentCollection<int, Availability>>  $availability
     * @param  callable(EloquentCollection<int, Availability>): bool  $test
     * @return list<int>
     */
    private function matching(Collection $availability, Shift $shift, callable $test): array
    {
        $ids = [];

        foreach ($availability as $employeeId => $rows) {
            if ($test($rows)) {
                $ids[] = (int) $employeeId;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Whether the employee said they would rather work then: a PREFERRED row
     * covers part of the shift's first day, and no UNAVAILABLE row decides
     * otherwise. A row for the date decides over the weekly rows, as in the
     * schedule's marks.
     *
     * @param  Collection<int, Availability>  $rows
     */
    public static function prefers(Collection $rows, Shift $shift): bool
    {
        $day = $shift->starts_at->startOfDay();
        $matching = $rows->filter(fn (Availability $row): bool => $row->appliesTo($day));
        $dated = $matching->whereNotNull('date');

        return ($dated->isNotEmpty() ? $dated : $matching)
            ->contains(fn (Availability $row): bool => $row->kind === Availability::PREFERRED && RequestChecks::overlaps($row, $shift));
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
}
