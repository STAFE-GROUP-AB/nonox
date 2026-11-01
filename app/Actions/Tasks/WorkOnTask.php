<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Firm\FirmCache;
use App\Domain\Tasks\TaskStatus;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\TaskActivity;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the assignee, or anyone with tasks:manage, does on a task: change
 * its status, tick the checklist and comment (ADR-028). Deleting needs
 * tasks:manage.
 */
class WorkOnTask
{
    public function status(User $actor, Task $task, TaskStatus $status): Task
    {
        $this->authorize($actor, 'work', $task);

        if ($task->status === $status) {
            return $task;
        }

        $company = $this->company($task);
        $before = $task->status;

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($actor, $task, $status, $before, $company): void {
            $task->forceFill([
                'status' => $status->value,
                'completed_at' => $status === TaskStatus::Done ? now() : null,
                'completed_by' => $status === TaskStatus::Done ? $actor->id : null,
            ])->save();

            Audit::record($status === TaskStatus::Done ? 'task.completed' : 'task.status_changed', $task, ['before' => $before->value, 'after' => $status->value], $company, $actor);
        }));

        if ($status === TaskStatus::Done && $task->created_by !== $actor->id) {
            User::query()->find($task->created_by)?->notify(TaskActivity::about(TaskActivity::COMPLETED, $task, $company, $actor));
        }

        FirmCache::bump($company->id);

        return $task;
    }

    public function comment(User $actor, Task $task, string $body): TaskComment
    {
        $this->authorize($actor, 'work', $task);
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > TaskComment::BODY_MAX) {
            throw ValidationException::withMessages(['comment' => __('Skriv en kommentar på högst :max tecken.', ['max' => TaskComment::BODY_MAX])]);
        }

        $company = $this->company($task);

        $comment = CurrentCompany::run($company, function () use ($actor, $task, $body, $company): TaskComment {
            $comment = TaskComment::query()->create(['task_id' => $task->id, 'user_id' => $actor->id, 'body' => $body]);
            // The comment's text may hold personal data; the audit trail says only that it was written.
            Audit::record('task.commented', $task, ['comment_id' => $comment->id, 'length' => mb_strlen($body)], $company, $actor);

            return $comment;
        });

        $recipients = array_values(array_unique(array_filter([$task->assignee_id, $task->created_by], fn (?int $id): bool => $id !== null && $id !== $actor->id)));

        foreach (User::query()->whereKey($recipients)->get() as $user) {
            $user->notify(TaskActivity::about(TaskActivity::COMMENTED, $task, $company, $actor));
        }

        return $comment;
    }

    public function addChecklistItem(User $actor, Task $task, string $label): TaskChecklistItem
    {
        $this->authorize($actor, 'work', $task);
        $label = trim($label);

        if ($label === '' || mb_strlen($label) > 200) {
            throw ValidationException::withMessages(['checklistLabel' => __('Skriv en punkt på högst 200 tecken.')]);
        }

        return CurrentCompany::run($this->company($task), function () use ($task, $label): TaskChecklistItem {
            $position = (int) TaskChecklistItem::query()->where('task_id', $task->id)->max('position') + 1;

            return TaskChecklistItem::query()->create(['task_id' => $task->id, 'label' => $label, 'position' => $position]);
        });
    }

    public function toggleChecklistItem(User $actor, Task $task, int $itemId): TaskChecklistItem
    {
        $this->authorize($actor, 'work', $task);

        return CurrentCompany::run($this->company($task), function () use ($actor, $task, $itemId): TaskChecklistItem {
            $item = TaskChecklistItem::query()->where('task_id', $task->id)->findOrFail($itemId);
            $item->forceFill($item->done_at === null ? ['done_at' => now(), 'done_by' => $actor->id] : ['done_at' => null, 'done_by' => null])->save();

            return $item;
        });
    }

    public function removeChecklistItem(User $actor, Task $task, int $itemId): void
    {
        $this->authorize($actor, 'update', $task);

        CurrentCompany::run($this->company($task), function () use ($task, $itemId): void {
            TaskChecklistItem::query()->where('task_id', $task->id)->findOrFail($itemId)->delete();
        });
    }

    public function delete(User $actor, Task $task): void
    {
        $this->authorize($actor, 'delete', $task);
        $company = $this->company($task);

        CurrentCompany::run($company, fn () => DB::transaction(function () use ($actor, $task, $company): void {
            Audit::record('task.deleted', $task, ['type' => $task->type->value, 'status' => $task->status->value], $company, $actor);
            $task->delete();
        }));

        FirmCache::bump($company->id);
    }

    private function authorize(User $actor, string $ability, Task $task): void
    {
        if (! $actor->can($ability, $task)) {
            throw new AuthorizationException;
        }
    }

    private function company(Task $task): Company
    {
        return Company::query()->findOrFail($task->company_id);
    }
}
