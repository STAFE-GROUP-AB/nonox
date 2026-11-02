<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ShiftCoster;
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
 * employee keeps seeing the published version. The estimate is recalculated.
 * Audited with before and after.
 */
final class SaveShift
{
    use GuardsPlanning;

    public const int MAX_MINUTES = 24 * 60;

    public function __construct(private readonly ShiftCoster $coster) {}

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?Shift $shift = null): Shift
    {
        $this->guard($user, $company);

        if ($shift?->status === Shift::CANCELLED) {
            throw ValidationException::withMessages(['shift' => __('Passet är inställt och kan inte ändras.')]);
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
        ], [], [
            'employee_id' => __('Person'),
            'area_id' => __('Avdelning'),
            'skill_id' => __('Kompetens'),
            'date' => __('Datum'),
            'start' => __('Starttid'),
            'end' => __('Sluttid'),
            'break_minutes' => __('Rast'),
            'note' => __('Anteckning'),
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

        return DB::transaction(function () use ($user, $company, $validated, $shift, $start, $end, $break): Shift {
            $before = $shift?->snapshot();
            $shift ??= new Shift(['status' => Shift::DRAFT, 'source' => 'MANUAL', 'created_by' => $user->id]);

            $shift->fill([
                'employee_id' => $validated['employee_id'] ?? null,
                'area_id' => $validated['area_id'] ?? null,
                'skill_id' => $validated['skill_id'] ?? null,
                'starts_at' => $start,
                'ends_at' => $end,
                'break_minutes' => $break,
                'note' => ($validated['note'] ?? null) === '' ? null : ($validated['note'] ?? null),
                'updated_by' => $user->id,
            ]);

            if ($before !== null && $before === $shift->snapshot() && ! $shift->isDirty('skill_id')) {
                return $shift;
            }

            if ($shift->status === Shift::PUBLISHED) {
                $shift->status = Shift::DRAFT;
            }

            $this->coster->apply($company, [$shift]);
            $shift->save();

            Audit::record($before === null ? 'planning.shift_created' : 'planning.shift_updated', $shift, $before === null
                ? ['after' => $shift->snapshot()]
                : Audit::changes($before, $shift->snapshot()), $company, $user);

            return $shift;
        });
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
