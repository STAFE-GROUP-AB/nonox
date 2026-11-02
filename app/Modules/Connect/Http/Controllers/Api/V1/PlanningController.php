<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Controllers\Api\V1;

use App\Modules\Connect\Http\Resources\CursorList;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Publication;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ShiftQuery;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Planering's schedule over the API (PLANNING_MODULE.md §9, ADR-032), read
 * only, with planning:view. By default the published schedule: what the
 * employees were shown, so a shift changed since it was published is given
 * as it was published and a draft never published is left out. With
 * include_drafts the plan as it stands now. Cancelled shifts are never
 * listed. Costs are not part of the API: they are estimates built from
 * individual wages. Three queries whatever the number of shifts.
 */
final class PlanningController
{
    public const int MAX_DAYS = 93;

    public function shifts(Request $request): JsonResponse
    {
        $input = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer'],
            'area_id' => ['nullable', 'integer'],
            'include_drafts' => ['nullable', 'in:true,false,1,0'],
        ], [], ['from' => __('från'), 'to' => __('till')])->validate();

        $from = CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['from'], LocalTimestamp::ZONE);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', (string) $input['to'], LocalTimestamp::ZONE);

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => __('Perioden måste börja före den slutar.')]);
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Hämta högst :days dagar åt gången.', ['days' => self::MAX_DAYS])]);
        }

        $drafts = in_array($input['include_drafts'] ?? 'false', ['true', '1'], true);

        // A shift moved since it was published is listed on the day it was published for, so look a week either side.
        $shifts = ($drafts ? ShiftQuery::startingOn($from, $to) : ShiftQuery::startingOn($from->subWeek(), $to->addWeek()))
            ->where('status', '<>', Shift::CANCELLED)
            ->when(! $drafts, fn ($query) => $query->whereNotNull('published_at'))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $rows = $shifts
            ->map(fn (Shift $shift): array => $drafts ? self::current($shift) : self::published($shift))
            ->filter(fn (array $row): bool => $row['day'] >= $from->toDateString() && $row['day'] <= $to->toDateString())
            ->filter(fn (array $row): bool => ! isset($input['employee_id']) || $row['employee_id'] === (int) $input['employee_id'])
            ->filter(fn (array $row): bool => ! isset($input['area_id']) || $row['area_id'] === (int) $input['area_id'])
            ->values();

        $names = self::names($rows->pluck('employee_id')->filter()->unique()->values());

        return new JsonResponse([
            'data' => $rows->map(fn (array $row): array => [
                ...$row,
                'employee_name' => $row['employee_id'] === null ? null : ($names[$row['employee_id']] ?? null),
            ])->all(),
            'meta' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'include_drafts' => $drafts,
                'count' => $rows->count(),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function areas(Request $request): JsonResponse
    {
        return CursorList::respond($request, Area::query(), fn (Area $area): array => [
            'id' => $area->id,
            'name' => $area->name,
            'colour' => $area->colour,
            'location_id' => $area->location_id,
            'sort' => $area->sort,
            'is_archived' => $area->archived_at !== null,
            'created_at' => self::time($area->created_at),
            'updated_at' => self::time($area->updated_at),
        ]);
    }

    public function publications(Request $request): JsonResponse
    {
        return CursorList::respond($request, Publication::query(), fn (Publication $publication): array => [
            'id' => $publication->id,
            'from' => $publication->from->toDateString(),
            'to' => $publication->to->toDateString(),
            'published_at' => self::time($publication->published_at),
            'shift_count' => $publication->shift_count,
            'notified_count' => $publication->notified_count,
            'created_at' => self::time($publication->created_at),
            'updated_at' => self::time($publication->updated_at),
        ]);
    }

    /**
     * The shift as the employee was shown it: the published version kept on
     * a shift changed since.
     *
     * @return array<string, mixed>
     */
    private static function published(Shift $shift): array
    {
        if (! $shift->hasUnpublishedChanges() || ! is_array($shift->published_snapshot)) {
            return [...self::current($shift), 'status' => 'published'];
        }

        $snapshot = $shift->published_snapshot;
        $start = CarbonImmutable::parse((string) $snapshot['starts_at'])->setTimezone(LocalTimestamp::ZONE);
        $end = CarbonImmutable::parse((string) $snapshot['ends_at'])->setTimezone(LocalTimestamp::ZONE);
        $break = (int) ($snapshot['break_minutes'] ?? 0);
        $employeeId = isset($snapshot['employee_id']) ? (int) $snapshot['employee_id'] : null;

        return [
            'id' => $shift->id,
            'day' => $start->toDateString(),
            'employee_id' => $employeeId,
            'area_id' => isset($snapshot['area_id']) ? (int) $snapshot['area_id'] : null,
            'skill_id' => $shift->skill_id,
            'starts_at' => $start->toAtomString(),
            'ends_at' => $end->toAtomString(),
            'break_minutes' => $break,
            'worked_minutes' => max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60) - $break),
            'note' => isset($snapshot['note']) ? (string) $snapshot['note'] : null,
            'is_open' => $employeeId === null,
            'status' => 'published',
            'has_unpublished_changes' => true,
            'published_at' => self::time($shift->published_at),
        ];
    }

    /** @return array<string, mixed> */
    private static function current(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'day' => $shift->day()->toDateString(),
            'employee_id' => $shift->employee_id,
            'area_id' => $shift->area_id,
            'skill_id' => $shift->skill_id,
            'starts_at' => $shift->starts_at->toAtomString(),
            'ends_at' => $shift->ends_at->toAtomString(),
            'break_minutes' => $shift->break_minutes,
            'worked_minutes' => $shift->workedMinutes(),
            'note' => $shift->note,
            'is_open' => $shift->isOpen(),
            'status' => $shift->status === Shift::PUBLISHED ? 'published' : 'draft',
            'has_unpublished_changes' => $shift->hasUnpublishedChanges(),
            'published_at' => self::time($shift->published_at),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private static function names(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return Employee::query()->whereKey($ids->all())->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => trim("{$employee->first_name} {$employee->last_name}")])
            ->all();
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }
}
