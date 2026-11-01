<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Firm\FirmCache;
use App\Domain\Tasks\TaskStatus;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskRecurrence;
use App\Models\User;
use App\Notifications\TaskActivity;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates the current period's task for every active recurring task, once:
 * the period is part of a unique key, so running twice creates nothing
 * (ADR-028).
 */
class GenerateRecurringTasks
{
    /** @return int the number of tasks created */
    public function __invoke(?CarbonImmutable $today = null): int
    {
        // A scheduled job for every company: each recurrence's task is created in its own company below.
        $recurrences = CompanyScope::bypass(fn () => TaskRecurrence::query()->where('active', true)->orderBy('id')->get());
        $created = 0;

        foreach ($recurrences as $recurrence) {
            $created += $this->for($recurrence, $today) instanceof Task ? 1 : 0;
        }

        return $created;
    }

    /** The task of the period that contains today, if it does not exist yet. */
    public function for(TaskRecurrence $recurrence, ?CarbonImmutable $today = null): ?Task
    {
        $today ??= CarbonImmutable::today();
        $company = Company::query()->find($recurrence->company_id);

        if (! $company instanceof Company || ! $recurrence->active) {
            return null;
        }

        $period = $recurrence->frequency->periodOf($today);

        $task = CurrentCompany::run($company, function () use ($recurrence, $company, $period): ?Task {
            if (Task::query()->where('recurrence_id', $recurrence->id)->where('period', $period)->exists()) {
                return null;
            }

            $assigneeId = $recurrence->assignee_id;
            $isMember = $assigneeId !== null && ($company->user_id === $assigneeId
                || DB::table('team_user')->where('team_id', $company->id)->where('user_id', $assigneeId)->exists());

            try {
                return DB::transaction(function () use ($recurrence, $company, $period, $isMember, $assigneeId): Task {
                    $task = Task::query()->create([
                        'title' => mb_substr("{$recurrence->title} – {$recurrence->frequency->periodLabel($period)}", 0, Task::TITLE_MAX),
                        'description' => $recurrence->description,
                        'type' => $recurrence->type->value,
                        'status' => TaskStatus::Todo->value,
                        'due_date' => $recurrence->frequency->dueDate($period, $recurrence->due_day)->toDateString(),
                        'assignee_id' => $isMember ? $assigneeId : null,
                        'created_by' => $recurrence->created_by,
                        'recurrence_id' => $recurrence->id,
                        'period' => $period,
                    ]);
                    Audit::record('task.created', $task, ['type' => $task->type->value, 'recurrence_id' => $recurrence->id, 'period' => $period, 'assignee_id' => $task->assignee_id], $company);

                    return $task;
                });
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });

        if ($task === null) {
            return null;
        }

        $creator = User::query()->find($recurrence->created_by);

        if ($task->assignee_id !== null && $creator instanceof User && $task->assignee_id !== $creator->id) {
            User::query()->find($task->assignee_id)?->notify(TaskActivity::about(TaskActivity::ASSIGNED, $task, $company, $creator));
        }

        FirmCache::bump($company->id);

        return $task;
    }
}
