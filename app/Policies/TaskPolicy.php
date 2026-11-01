<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Company;
use App\Models\Task;
use App\Models\User;

/**
 * Tasks (ADR-028): tasks:view sees all of a company's tasks, tasks:manage
 * creates, assigns, edits and deletes them. The person a task is assigned
 * to always sees it and may work on it (status, checklist, comments), so a
 * firm can hand work to the client whatever the client's role.
 */
class TaskPolicy
{
    public function viewAny(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'tasks:view');
    }

    public function create(User $user, Company $company): bool
    {
        return $user->hasTeamPermission($company, 'tasks:manage');
    }

    public function view(User $user, Task $task): bool
    {
        return $this->isAssignee($user, $task) || $this->can($user, $task, 'tasks:view');
    }

    public function update(User $user, Task $task): bool
    {
        return $this->can($user, $task, 'tasks:manage');
    }

    /** Change the status, tick the checklist and comment. */
    public function work(User $user, Task $task): bool
    {
        return $this->isAssignee($user, $task) || $this->can($user, $task, 'tasks:manage');
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    private function isAssignee(User $user, Task $task): bool
    {
        if ($task->assignee_id !== $user->id) {
            return false;
        }

        $company = $this->company($task);

        // Still a member: a removed member's old assignment gives nothing.
        return $company instanceof Company && $user->belongsToTeam($company);
    }

    private function can(User $user, Task $task, string $permission): bool
    {
        $company = $this->company($task);

        return $company instanceof Company && $user->hasTeamPermission($company, $permission);
    }

    private function company(Task $task): ?Company
    {
        if ($task->relationLoaded('company') && $task->company instanceof Company) {
            return $task->company;
        }

        return Company::query()->find($task->company_id);
    }
}
