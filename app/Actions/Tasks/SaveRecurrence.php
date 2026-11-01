<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskFrequency;
use App\Domain\Tasks\TaskType;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskRecurrence;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A recurring task such as "Moms varje månad" (ADR-028). The period's task
 * is created right away, the following ones by tasks:generate-recurring.
 */
class SaveRecurrence
{
    public function __construct(private readonly GenerateRecurringTasks $generate) {}

    /**
     * @param  array<string, mixed>  $input  title, description, type, frequency, due_day, assignee_id
     */
    public function __invoke(User $actor, Company $company, array $input): TaskRecurrence
    {
        if (! $actor->can('create', [Task::class, $company])) {
            throw new AuthorizationException;
        }

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:'.Task::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.Task::DESCRIPTION_MAX],
            'type' => ['required', Rule::enum(TaskType::class)],
            'frequency' => ['required', Rule::enum(TaskFrequency::class)],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'assignee_id' => ['nullable', 'integer'],
        ], [], ['title' => __('rubrik'), 'due_day' => __('dag i månaden')])->validate();

        $assigneeId = blank($data['assignee_id'] ?? null) ? null : (int) $data['assignee_id'];

        if ($assigneeId !== null) {
            SaveTask::assertMember($company, $assigneeId);
        }

        $recurrence = CurrentCompany::run($company, function () use ($actor, $company, $data, $assigneeId): TaskRecurrence {
            $recurrence = TaskRecurrence::query()->create([
                'title' => trim((string) $data['title']),
                'description' => blank($data['description'] ?? null) ? null : trim((string) $data['description']),
                'type' => $data['type'],
                'frequency' => $data['frequency'],
                'due_day' => (int) $data['due_day'],
                'assignee_id' => $assigneeId,
                'active' => true,
                'created_by' => $actor->id,
            ]);
            Audit::record('task.recurrence_created', $recurrence, ['frequency' => $recurrence->frequency->value, 'type' => $recurrence->type->value], $company, $actor);

            return $recurrence;
        });

        $this->generate->for($recurrence);

        return $recurrence;
    }

    public function stop(User $actor, TaskRecurrence $recurrence): void
    {
        $company = Company::query()->findOrFail($recurrence->company_id);

        if (! $actor->can('create', [Task::class, $company])) {
            throw new AuthorizationException;
        }

        CurrentCompany::run($company, function () use ($actor, $company, $recurrence): void {
            $recurrence->forceFill(['active' => false])->save();
            Audit::record('task.recurrence_stopped', $recurrence, [], $company, $actor);
        });
    }
}
