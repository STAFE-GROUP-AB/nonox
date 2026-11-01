<?php

declare(strict_types=1);

namespace App\Support\Overview;

use App\Domain\Overview\Contracts\PersonalTodoProvider;
use App\Domain\Overview\TodoItem;
use App\Domain\Tasks\TaskPriority;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The open tasks assigned to the user in the company, for "Att göra" on the
 * company's Översikt (ADR-027, ADR-028): what the firm has handed to the
 * client, or the client to the firm. One query. Personal, so the overview
 * caches it per user.
 */
final class TaskTodoProvider implements PersonalTodoProvider
{
    private const int LIMIT = 20;

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        $tasks = CurrentCompany::run($company, fn () => Task::query()->open()->where('assignee_id', $user->id)
            ->orderByRaw('due_date asc nulls last')->orderBy('id')->limit(self::LIMIT)->get());

        return array_values($tasks->map(fn (Task $task): TodoItem => new TodoItem(
            "task:{$task->id}",
            'task',
            $task->title,
            $task->status->label(),
            route('tasks.show', $task),
            $task->priority === TaskPriority::High ? TodoItem::PRIORITY_VAT : TodoItem::PRIORITY_OTHER,
            $task->due_date,
        ))->all());
    }

    public function invalidatedBy(): array
    {
        return [Task::class, TaskComment::class];
    }
}
