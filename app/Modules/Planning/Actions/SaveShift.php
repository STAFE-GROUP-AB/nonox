<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\AbsenceDays;
use App\Modules\Planning\Support\CostCenterInput;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ShiftCoster;
use App\Modules\Planning\Support\ShiftTasks;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Creates or changes a shift: who (or nobody, an open shift), when, the
 * break, area, skill and note. The schedule grid calls it for every drop,
 * move and resize. Times come as a date with wall-clock times (an end at or
 * before the start ends the next day) or as two instants. A change to a
 * published shift makes it a draft again until the next publication; the
 * employee keeps seeing the published version. The estimate is recalculated
 * and the recurring tasks of the task templates are put on the shift.
 * Audited with before and after.
 *
 * The cost centre (ADR-037, gap 7) is typed by code. Left out, a new shift,
 * or one moved to another area, takes the area's (while it is active) and
 * a shift otherwise keeps its own; given empty, the area's applies. Null
 * means the employment's cost centre in payroll. A change of only the cost
 * centre does not unpublish the shift: the employee does not see it.
 */
final class SaveShift
{
    use GuardsPlanning;

    public const int MAX_MINUTES = 24 * 60;

    public function __construct(private readonly ShiftCoster $coster, private readonly ShiftTasks $tasks) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  ?string  $source  where the change comes from (Shift::SOURCES), e.g. PROPOSAL when a planner accepts a schedule proposal; null keeps the shift's own
     */
    public function __invoke(User $user, Company $company, array $input, ?Shift $shift = null, ?string $source = null): Shift
    {
        $this->guard($user, $company);

        if ($shift?->status === Shift::CANCELLED) {
            throw ValidationException::withMessages(['shift' => __('Passet är inställt och kan inte ändras.')]);
        }

        if ($source !== null && ! in_array($source, Shift::SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown shift source {$source}.");
        }

        $validated = Validator::make($input, [
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $company->id)],
            'area_id' => ['nullable', 'integer', Rule::exists('planning_areas', 'id')->where('company_id', $company->id)],
            'skill_id' => ['nullable', 'integer', Rule::exists('planning_skills', 'id')->where('company_id', $company->id)],
            'date' => ['required_without:starts_at', 'nullable', 'date_format:Y-m-d'],
            'start' => ['required_with:date', 'nullable', 'date_format:H:i'],
            'end' => ['required_with:date', 'nullable', 'date_format:H:i'],
            'starts_at' => ['required_without:date', 'nullable', 'date'],
            'ends_at' => ['required_with:starts_at', 'nullable', 'date'],
            'break_minutes' => ['nullable', 'integer', 'between:0,720'],
            'note' => ['nullable', 'string', 'max:500'],
            'cost_center' => ['nullable', 'string', 'max:20'],
        ], [], [
            'employee_id' => __('Person'),
            'area_id' => __('Avdelning'),
            'skill_id' => __('Kompetens'),
            'date' => __('Datum'),
            'start' => __('Starttid'),
            'end' => __('Sluttid'),
            'break_minutes' => __('Rast'),
            'note' => __('Anteckning'),
            'cost_center' => __('Kostnadsställe'),
        ])->validate();

        [$start, $end] = self::interval($validated);
        $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        $break = (int) ($validated['break_minutes'] ?? 0);

        if ($minutes <= 0) {
            throw ValidationException::withMessages(['end' => __('Passet måste sluta efter att det börjar.')]);
        }

        if ($minutes > self::MAX_MINUTES) {
            throw ValidationException::withMessages(['end' => __('Ett pass kan vara högst 24 timmar.')]);
        }

        if ($break >= $minutes) {
            throw ValidationException::withMessages(['break_minutes' => __('Rasten måste vara kortare än passet.')]);
        }

        $areaId = isset($validated['area_id']) ? (int) $validated['area_id'] : null;
        $costCenterId = match (true) {
            filled($validated['cost_center'] ?? null) => CostCenterInput::resolve($company, $validated['cost_center'], $shift?->cost_center_id, 'cost_center'),
            $shift === null, $shift->area_id !== $areaId => CostCenterInput::areaDefault($company, $areaId),
            // Empty on the same area: the area's, and a shift already on it keeps it also when it has become inactive.
            array_key_exists('cost_center', $input) => CostCenterInput::areaDefault($company, $areaId, $shift->cost_center_id),
            default => $shift->cost_center_id,
        };

        return DB::transaction(function () use ($user, $company, $validated, $shift, $start, $end, $break, $source, $costCenterId): Shift {
            $before = $shift?->snapshot();
            $beforeCostCenter = $shift?->cost_center_id;
            $shift ??= new Shift(['status' => Shift::DRAFT, 'source' => 'MANUAL', 'created_by' => $user->id]);

            $shift->fill([
                'employee_id' => $validated['employee_id'] ?? null,
                'area_id' => $validated['area_id'] ?? null,
                'skill_id' => $validated['skill_id'] ?? null,
                'starts_at' => $start,
                'ends_at' => $end,
                'break_minutes' => $break,
                'note' => ($validated['note'] ?? null) === '' ? null : ($validated['note'] ?? null),
                'cost_center_id' => $costCenterId,
                'updated_by' => $user->id,
            ]);

            if ($before !== null && $before === $shift->snapshot() && ! $shift->isDirty('skill_id') && ! $shift->isDirty('cost_center_id')) {
                return $shift;
            }

            // The employee does not see the cost centre: a change of only that keeps the shift published.
            if ($shift->status === Shift::PUBLISHED && ($before !== $shift->snapshot() || $shift->isDirty('skill_id'))) {
                $shift->status = Shift::DRAFT;
            }

            if ($source !== null) {
                $shift->source = $source;
            }

            $this->coster->apply($company, [$shift]);
            $shift->save();
            $this->tasks->sync($company, [$shift], includePublished: false);

            Audit::record($before === null ? 'planning.shift_created' : 'planning.shift_updated', $shift, $before === null
                ? ['after' => [...$shift->snapshot(), 'cost_center_id' => $shift->cost_center_id]]
                : Audit::changes([...$before, 'cost_center_id' => $beforeCostCenter], [...$shift->snapshot(), 'cost_center_id' => $shift->cost_center_id]), $company, $user);

            return $shift;
        });
    }

    /** The warning to show after a save when the person has approved absence on the shift's day: never blocks, never says why (AbsenceDays). */
    public function absenceWarning(Shift $shift): ?string
    {
        return isset(AbsenceDays::shiftsOfAbsent([$shift])[(string) $shift->id]) ? AbsenceDays::warning() : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function interval(array $validated): array
    {
        try {
            if (($validated['date'] ?? null) !== null) {
                $start = CarbonImmutable::parse("{$validated['date']} {$validated['start']}", LocalTimestamp::ZONE);
                $end = CarbonImmutable::parse("{$validated['date']} {$validated['end']}", LocalTimestamp::ZONE);

                return [$start, $end->lessThanOrEqualTo($start) ? CarbonImmutable::parse($end->addDay()->toDateString()." {$validated['end']}", LocalTimestamp::ZONE) : $end];
            }

            return [
                CarbonImmutable::parse((string) $validated['starts_at'], LocalTimestamp::ZONE)->setTimezone(LocalTimestamp::ZONE)->startOfMinute(),
                CarbonImmutable::parse((string) $validated['ends_at'], LocalTimestamp::ZONE)->setTimezone(LocalTimestamp::ZONE)->startOfMinute(),
            ];
        } catch (Throwable) {
            throw ValidationException::withMessages(['start' => __('Ange tiden som tt:mm.')]);
        }
    }
}
