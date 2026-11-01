<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Firm\FirmCache;
use App\Domain\Tasks\TaskLinks;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Notifications\TaskActivity;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates or changes a task in a company (ADR-028). Needs tasks:manage. The
 * assignee must be the owner or a member of that company; anyone else
 * answers 404, as for a user that does not exist. The new assignee gets an
 * in-app notice.
 */
class SaveTask
{
    /**
     * @param  array<string, mixed>  $input  title, description, type, priority, status, due_date,
     *                                       assignee_id, subject_type, subject_id, checklist (list of lines, new tasks only)
     */
    public function __invoke(User $actor, Company $company, array $input, ?Task $task = null): Task
    {
        if ($task !== null && $task->company_id !== $company->id) {
            abort(404);
        }

        $allowed = $task === null ? $actor->can('create', [Task::class, $company]) : $actor->can('update', $task);

        if (! $allowed) {
            throw new AuthorizationException;
        }

        $data = Validator::make($input, [
            'title' => ['required', 'string', 'max:'.Task::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.Task::DESCRIPTION_MAX],
            'type' => ['required', Rule::enum(TaskType::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'assignee_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', Rule::in(array_keys(TaskLinks::TYPES))],
            'subject_id' => ['nullable', 'string', 'max:40', 'required_with:subject_type'],
            'checklist' => ['nullable', 'array', 'max:30'],
            'checklist.*' => ['string', 'max:200'],
        ], [], [
            'title' => __('rubrik'),
            'due_date' => __('sista dag'),
        ])->validate();

        $assigneeId = isset($data['assignee_id']) && $data['assignee_id'] !== '' ? (int) $data['assignee_id'] : null;

        if ($assigneeId !== null) {
            self::assertMember($company, $assigneeId);
        }

        $before = $task?->assignee_id;

        $saved = CurrentCompany::run($company, fn (): Task => DB::transaction(function () use ($actor, $company, $data, $task, $assigneeId): Task {
            $subjectType = $data['subject_type'] ?? null;
            $subjectId = $subjectType === null ? null : (string) TaskLinks::find($subjectType, (string) ($data['subject_id'] ?? ''))->getKey();
            $status = isset($data['status']) ? TaskStatus::from($data['status']) : ($task === null ? TaskStatus::Todo : $task->status);

            $model = $task ?? new Task(['created_by' => $actor->id]);
            $model->fill([
                'title' => trim((string) $data['title']),
                'description' => blank($data['description'] ?? null) ? null : trim((string) $data['description']),
                'type' => $data['type'],
                'priority' => $data['priority'] ?? TaskPriority::Normal->value,
                'status' => $status->value,
                'due_date' => $data['due_date'] ?? null,
                'assignee_id' => $assigneeId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ]);
            $model->forceFill($status === TaskStatus::Done
                ? ['completed_at' => $model->completed_at ?? now(), 'completed_by' => $model->completed_by ?? $actor->id]
                : ['completed_at' => null, 'completed_by' => null]);
            $created = ! $model->exists;
            $changes = $created ? [] : array_keys($model->getDirty());
            $model->save();

            if ($created) {
                foreach (array_values(array_filter(array_map('trim', (array) ($data['checklist'] ?? [])))) as $position => $label) {
                    TaskChecklistItem::query()->create(['task_id' => $model->id, 'label' => $label, 'position' => $position]);
                }
            }

            // The audit trail keeps what happened, not the text of the task.
            Audit::record($created ? 'task.created' : 'task.updated', $model, [
                'type' => $model->type->value,
                'status' => $model->status->value,
                'assignee_id' => $model->assignee_id,
                'due_date' => $model->due_date?->toDateString(),
                ...($created ? [] : ['changed' => $changes]),
            ], $company, $actor);

            return $model;
        }));

        if ($saved->assignee_id !== null && $saved->assignee_id !== $before) {
            Audit::record('task.assigned', $saved, ['before' => $before, 'after' => $saved->assignee_id], $company, $actor);

            if ($saved->assignee_id !== $actor->id) {
                User::query()->find($saved->assignee_id)?->notify(TaskActivity::about(TaskActivity::ASSIGNED, $saved, $company, $actor));
            }
        }

        FirmCache::bump($company->id);

        return $saved;
    }

    /**
     * The owner or a member of the company; anyone else is "not found",
     * whether the user exists or not, so other memberships are not revealed.
     */
    public static function assertMember(Company $company, int $userId): void
    {
        $member = $company->user_id === $userId
            || DB::table('team_user')->where('team_id', $company->id)->where('user_id', $userId)->exists();

        abort_unless($member, 404);
    }
}
