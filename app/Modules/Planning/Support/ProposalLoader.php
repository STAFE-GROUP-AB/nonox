<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Employment;
use App\Modules\Planning\Domain\Proposal\CandidateShift;
use App\Modules\Planning\Domain\Proposal\DemandBlock;
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

    /** The prefix of a new shift's slot id: new-0, new-1 … by its place in the proposal's list of new shifts. */
    public const string NEW_SLOT = 'new-';

    /** @var Collection<int|string, EloquentCollection<int, Availability>> availability of the last load, by employee */
    private Collection $availability;

    /** @var array<int, array<string, string>> approved absence of the last load, by employee and date */
    private array $absence = [];

    /** @var list<int> the employees of the last load */
    private array $employeeIds = [];

    public function __construct(private readonly ModuleManager $modules)
    {
        $this->availability = collect();
    }

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
            // Only the dates and the contracted hours: no pay column is read.
            ->with(['employments' => fn ($query) => $query->select(['id', 'employee_id', 'start_date', 'end_date', 'weekly_hours'])->orderBy('start_date')])
            ->orderBy('id')
            ->get(['id', 'birth_date']);
        $ids = $employees->pluck('id')->all();

        $skills = EmployeeSkill::query()->whereIn('employee_id', $ids)->get(['employee_id', 'skill_id'])->groupBy('employee_id');
        $availability = Availability::query()
            ->whereIn('employee_id', $ids)
            ->where(fn ($query) => $query->whereNotNull('weekday')->orWhereBetween('date', [$from->toDateString(), $to->addDay()->toDateString()]))
            ->get()
            ->groupBy('employee_id');
        // The fairness window (for the contracted hours) and one day more than the period: a night shift on the last day runs into the next.
        $windowFrom = $from->subWeeks($fairnessWeeks);
        $absence = AbsenceDays::between($windowFrom, $to->addDay());
        $shifts = ShiftQuery::startingOn($from->subWeeks($fairnessWeeks + self::CONTEXT_WEEKS), $to->addWeeks(self::CONTEXT_WEEKS))
            ->whereIn('employee_id', $ids)
            ->where('status', '<>', Shift::CANCELLED)
            ->get(['id', 'employee_id', 'starts_at', 'ends_at', 'break_minutes', 'area_id'])
            ->groupBy('employee_id');

        $this->availability = $availability;
        $this->absence = $absence;
        $this->employeeIds = array_values(array_map('intval', $ids));
        $slots = $open->map(fn (Shift $shift): ProposalSlot => $this->slot((string) $shift->id, $shift))->all();

        $candidates = $employees->map(fn (Employee $employee): ProposalCandidate => new ProposalCandidate(
            employeeId: $employee->id,
            skillIds: array_values(array_map('intval', $skills->get($employee->id, collect())->pluck('skill_id')->all())),
            birthDate: $employee->birth_date,
            weeklyHours: $employee->employments->filter(fn (Employment $employment): bool => $employment->start_date->lessThanOrEqualTo($to))->last()?->weekly_hours,
            employments: array_values($employee->employments->map(fn (Employment $employment): array => [$employment->start_date, $employment->end_date])->all()),
            shifts: array_values($shifts->get($employee->id, collect())->map(fn (Shift $shift): CandidateShift => new CandidateShift((string) $shift->id, $shift->starts_at, $shift->ends_at, $shift->break_minutes, $shift->area_id))->all()),
            absentWeekdays: self::absentWeekdays($absence[$employee->id] ?? [], $windowFrom, $to),
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
     * The input with the new shifts made from the demand added as slots
     * (v2, P8), each checked against the availability and approved absence
     * of the last load exactly like an open shift. No query.
     *
     * @param  list<DemandBlock>  $blocks
     */
    public function withBlocks(ProposalInput $input, array $blocks): ProposalInput
    {
        if ($blocks === []) {
            return $input;
        }

        $slots = $input->slots;

        foreach ($blocks as $index => $block) {
            $shift = (new Shift)->forceFill([
                'starts_at' => $block->start,
                'ends_at' => $block->end,
                'break_minutes' => $block->breakMinutes,
                'area_id' => $block->areaId,
            ]);
            $slots[] = $this->slot(self::NEW_SLOT.$index, $shift);
        }

        return new ProposalInput(
            slots: $slots,
            candidates: $input->candidates,
            periodFrom: $input->periodFrom,
            periodTo: $input->periodTo,
            rules: $input->rules,
            minorsEnabled: $input->minorsEnabled,
            minorsRules: $input->minorsRules,
            weights: $input->weights,
            fairnessWeeks: $input->fairnessWeeks,
            unsocialFrom: $input->unsocialFrom,
            unsocialTo: $input->unsocialTo,
            maxSwaps: $input->maxSwaps,
        );
    }

    /** An open or a new shift as a slot, with who cannot, who is absent and who would rather work then. */
    private function slot(string $id, Shift $shift): ProposalSlot
    {
        return new ProposalSlot(
            id: $id,
            start: $shift->starts_at,
            end: $shift->ends_at,
            breakMinutes: (int) $shift->break_minutes,
            areaId: $shift->area_id,
            skillId: $shift->skill_id,
            unavailable: $this->matching($this->availability, $shift, fn (EloquentCollection $rows): bool => self::unavailableDuring($rows, $shift)),
            absent: array_values(array_filter($this->employeeIds, fn (int $id): bool => isset($this->absence[$id]) && AbsenceDays::absentDuring($this->absence, $id, $shift))),
            preferred: $this->matching($this->availability, $shift, fn (EloquentCollection $rows): bool => self::prefers($rows, $shift)),
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
     * Whether the employee said a time the shift covers does not suit them,
     * on any day the shift touches: a night shift from Friday 22:00 to
     * Saturday 06:00 is checked against Friday's and Saturday's rows. On each
     * day a row for the date decides over the weekly rows, as in the
     * schedule's marks; a row with times blocks only when they overlap the
     * part of the shift on that day.
     *
     * @param  Collection<int, Availability>  $rows
     */
    public static function unavailableDuring(Collection $rows, Shift $shift): bool
    {
        $start = $shift->starts_at->setTimezone(LocalTimestamp::ZONE);
        $end = $shift->ends_at->setTimezone(LocalTimestamp::ZONE);

        foreach (AbsenceDays::days($shift) as $date) {
            $day = CarbonImmutable::parse($date, LocalTimestamp::ZONE)->startOfDay();
            $matching = $rows->filter(fn (Availability $row): bool => $row->appliesTo($day));
            $dated = $matching->whereNotNull('date');

            foreach ($dated->isNotEmpty() ? $dated : $matching as $row) {
                if ($row->kind !== Availability::UNAVAILABLE) {
                    continue;
                }

                $from = $row->isWholeDay() ? $day : CarbonImmutable::parse("{$date} {$row->from}", LocalTimestamp::ZONE);
                $to = $row->isWholeDay() ? $day->addDay() : CarbonImmutable::parse("{$date} {$row->to}", LocalTimestamp::ZONE);

                if ($start->lessThan($to) && $end->greaterThan($from)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Weekdays of approved absence from the first day of the fairness window
     * to the last day of the period, a part day as a half: time the person
     * could not be planned, taken off their contracted hours.
     *
     * @param  array<string, string>  $marks  date => AbsenceDays mark
     */
    private static function absentWeekdays(array $marks, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $days = 0.0;

        foreach ($marks as $date => $mark) {
            $day = CarbonImmutable::parse($date, LocalTimestamp::ZONE);

            if ($date < $from->toDateString() || $date > $to->toDateString() || $day->isWeekend()) {
                continue;
            }

            $days += $mark === AbsenceDays::FULL ? 1.0 : ($mark === AbsenceDays::PARTIAL ? 0.5 : 0.0);
        }

        return $days;
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
