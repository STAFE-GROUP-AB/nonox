<?php

declare(strict_types=1);

namespace App\Support\Overview;

use App\Domain\Tasks\TaskPriority;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * The open tasks assigned to the user in the company, for "Att göra" on the
 * company's Översikt: what the firm has handed to the client, or to a
 * colleague. One query.
 */
final class TaskTodoProvider implements TodoProvider
{
    /** Shown after the deadlines of the system's own to-dos of the same day. */
    private const int PRIORITY = 15;

    private const int LIMIT = 20;

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        $tasks = CurrentCompany::run($company, fn () => Task::query()->open()->where('assignee_id', $user->id)
            ->orderByRaw('due_date asc nulls last')->orderBy('id')->limit(self::LIMIT)->get());

        return array_values($tasks->map(fn (Task $task): array => [
            'key' => "task:{$task->id}",
            'icon' => 'task',
            'title' => $task->title,
            'subtitle' => $task->status->label(),
            'url' => route('tasks.show', $task),
            'priority' => self::PRIORITY + $task->priority->rank() - TaskPriority::Normal->rank(),
            'due' => $task->due_date?->toDateString(),
        ])->all());
    }

    public function invalidatedBy(): array
    {
        return [Task::class, TaskComment::class];
    }
}
