<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\Template;
use App\Modules\Planning\Models\TemplateShift;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Templates (mallar): name, length in weeks and notes; their shifts one by
 * one; "save these weeks as a template"; and removal. Audited.
 */
final class SaveTemplate
{
    use GuardsPlanning;

    public const int MAX_WEEKS = 8;

    /** @param  array<string, mixed>  $input */
    public function __invoke(User $user, Company $company, array $input, ?Template $template = null): Template
    {
        $this->guard($user, $company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'weeks' => ['required', 'integer', 'between:1,'.self::MAX_WEEKS],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => __('Namn'), 'weeks' => __('Antal veckor'), 'notes' => __('Anteckningar')])->validate();

        return DB::transaction(function () use ($user, $company, $validated, $template): Template {
            $before = $template?->only(['name', 'weeks', 'notes']) ?? [];
            $template ??= new Template(['created_by' => $user->id]);
            $template->fill(['name' => $validated['name'], 'weeks' => (int) $validated['weeks'], 'notes' => $validated['notes'] ?? null])->save();

            if ($before !== []) {
                // A shorter template drops the shifts of the weeks it no longer has.
                TemplateShift::query()->where('template_id', $template->id)->where('week_index', '>=', $template->weeks)->delete();
            }

            Audit::record($before === [] ? 'planning.template_created' : 'planning.template_updated', $template, Audit::changes($before, $template->only(['name', 'weeks', 'notes'])), $company, $user);

            return $template;
        });
    }

    /**
     * Saves the shifts of whole weeks of the schedule as a new template.
     */
    public function fromWeeks(User $user, Company $company, string $name, CarbonImmutable $firstWeek, int $weeks, bool $withPeople = true): Template
    {
        $template = $this($user, $company, ['name' => $name, 'weeks' => $weeks]);
        $monday = $firstWeek->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $shifts = ShiftQuery::startingOn($monday, $monday->addWeeks($weeks)->subDay())->where('status', '<>', Shift::CANCELLED)->orderBy('starts_at')->get();

        DB::transaction(function () use ($template, $shifts, $monday, $withPeople, $user, $company): void {
            foreach ($shifts as $shift) {
                TemplateShift::create([
                    'template_id' => $template->id,
                    'week_index' => intdiv((int) round($monday->diffInDays($shift->starts_at->startOfDay())), 7),
                    'weekday' => $shift->starts_at->dayOfWeekIso,
                    'start' => $shift->starts_at->format('H:i'),
                    'end' => $shift->ends_at->format('H:i'),
                    'break_minutes' => $shift->break_minutes,
                    'employee_id' => $withPeople ? $shift->employee_id : null,
                    'area_id' => $shift->area_id,
                    'skill_id' => $shift->skill_id,
                    'note' => $shift->note,
                ]);
            }

            Audit::record('planning.template_saved_from_schedule', $template, ['from' => $monday->toDateString(), 'weeks' => $template->weeks, 'shifts' => $shifts->count(), 'with_people' => $withPeople], $company, $user);
        });

        return $template;
    }

    /** @param  array<string, mixed>  $input */
    public function saveShift(User $user, Company $company, Template $template, array $input, ?TemplateShift $shift = null): TemplateShift
    {
        $this->guard($user, $company);

        $validated = Validator::make($input, [
            'week_index' => ['required', 'integer', 'between:0,'.($template->weeks - 1)],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'between:0,720'],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $company->id)],
            'area_id' => ['nullable', 'integer', Rule::exists('planning_areas', 'id')->where('company_id', $company->id)],
            'skill_id' => ['nullable', 'integer', Rule::exists('planning_skills', 'id')->where('company_id', $company->id)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'week_index' => __('Vecka'), 'weekday' => __('Veckodag'), 'start' => __('Starttid'), 'end' => __('Sluttid'), 'break_minutes' => __('Rast'),
        ])->validate();

        $validated['break_minutes'] = (int) ($validated['break_minutes'] ?? 0);

        return DB::transaction(function () use ($user, $company, $template, $validated, $shift): TemplateShift {
            $before = $shift?->only(array_keys($validated)) ?? [];
            $shift ??= new TemplateShift(['template_id' => $template->id]);
            $shift->fill($validated)->save();
            Audit::record('planning.template_shift_saved', $template, ['shift_id' => $shift->id, ...Audit::changes($before, $shift->only(array_keys($validated)))], $company, $user);

            return $shift;
        });
    }

    public function deleteShift(User $user, Company $company, TemplateShift $shift): void
    {
        $this->guard($user, $company);
        Audit::record('planning.template_shift_deleted', $shift->template, ['shift' => $shift->only(['week_index', 'weekday', 'start', 'end', 'employee_id', 'area_id'])], $company, $user);
        $shift->delete();
    }

    public function delete(User $user, Company $company, Template $template): void
    {
        $this->guard($user, $company);

        DB::transaction(function () use ($user, $company, $template): void {
            Audit::record('planning.template_deleted', $template, ['name' => $template->name], $company, $user);
            $template->delete();
        });
    }
}
