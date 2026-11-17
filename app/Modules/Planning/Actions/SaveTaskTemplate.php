<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\TaskTemplate;
use App\Modules\Planning\Support\ShiftTasks;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Task templates (uppgiftsmallar, ADR-037 gap 4): which tasks belong on
 * which shifts. After a change the tasks on the shifts that have not started
 * (or are not published yet) follow the templates, except on a shift where a
 * task has been ticked. The task text must not hold personal data; the audit
 * log holds ids and field names only, never the text.
 */
final class SaveTaskTemplate
{
    use GuardsPlanning;

    public function __construct(private readonly ShiftTasks $tasks) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{template: TaskTemplate, added: int}
     */
    public function __invoke(User $user, Company $company, array $input, ?TaskTemplate $template = null): array
    {
        $this->guard($user, $company);

        $validated = Validator::make($input, [
            'title' => ['required', 'string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:500'],
            'area_id' => ['nullable', 'integer', Rule::exists('planning_areas', 'id')->where('company_id', $company->id)],
            'skill_id' => ['nullable', 'integer', Rule::exists('planning_skills', 'id')->where('company_id', $company->id)],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],
        ], [], [
            'title' => __('Uppgift'),
            'instructions' => __('Instruktion'),
            'area_id' => __('Avdelning'),
            'skill_id' => __('Kompetens'),
            'weekdays' => __('Veckodagar'),
            'due_time' => __('Klockslag'),
            'valid_from' => __('Gäller från'),
            'valid_to' => __('Gäller till'),
        ])->validate();

        $weekdays = array_values(array_unique(array_map('intval', $validated['weekdays'])));
        sort($weekdays);

        return DB::transaction(function () use ($user, $company, $validated, $weekdays, $template): array {
            $creating = $template === null;
            $template ??= new TaskTemplate(['created_by' => $user->id]);
            $template->fill([
                'title' => trim((string) $validated['title']),
                'instructions' => self::blank($validated['instructions'] ?? null),
                'area_id' => $validated['area_id'] ?? null,
                'skill_id' => $validated['skill_id'] ?? null,
                'weekdays' => $weekdays,
                'due_time' => self::blank($validated['due_time'] ?? null),
                'valid_from' => self::blank($validated['valid_from'] ?? null),
                'valid_to' => self::blank($validated['valid_to'] ?? null),
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            $changed = $creating ? TaskTemplate::FIELDS : array_values(array_intersect(TaskTemplate::FIELDS, array_keys($template->getDirty())));
            $template->save();
            $added = $this->tasks->syncUpcoming($company);

            Audit::record($creating ? 'planning.task_template_created' : 'planning.task_template_updated', $template, ['fields' => $changed, 'tasks_added' => $added], $company, $user);

            return ['template' => $template, 'added' => $added];
        });
    }

    public function delete(User $user, Company $company, TaskTemplate $template): void
    {
        $this->guard($user, $company);

        DB::transaction(function () use ($user, $company, $template): void {
            Audit::record('planning.task_template_deleted', $template, [], $company, $user);
            // Switched off first, so its tasks leave the shifts that have not started (unless ticked); what is left keeps no link.
            $template->forceFill(['is_active' => false])->save();
            $this->tasks->syncUpcoming($company);
            $template->delete();
        });
    }

    private static function blank(mixed $value): mixed
    {
        return $value === '' || $value === null ? null : $value;
    }
}
