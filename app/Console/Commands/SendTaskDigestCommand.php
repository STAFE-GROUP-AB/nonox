<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\TaskDigestMail;
use App\Models\Task;
use App\Models\User;
use App\Support\Branding\FirmBranding;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Every weekday morning, one e-mail to each user who asked for it with the
 * open tasks assigned to them in the companies they still belong to
 * (ADR-028). Nothing is sent to a user with nothing open.
 */
class SendTaskDigestCommand extends Command
{
    protected $signature = 'tasks:send-digest';

    protected $description = 'Skickar morgonens sammanfattning av öppna uppgifter till dem som har valt den';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $users = User::query()->where('task_digest', 'DAILY')->whereNull('anonymised_at')->get()->keyBy('id');

        if ($users->isEmpty()) {
            $this->comment('Ingen har valt sammanfattningen.');

            return self::SUCCESS;
        }

        // Tasks of every company, but only those assigned to a user who still owns or belongs to the task's company.
        $tasks = CompanyScope::bypass(fn () => Task::query()->open()
            ->whereIn('tasks.assignee_id', $users->keys()->all())
            ->join('teams', 'teams.id', '=', 'tasks.company_id')
            ->where(fn ($query) => $query->whereColumn('teams.user_id', 'tasks.assignee_id')
                ->orWhereExists(fn ($exists) => $exists->selectRaw('1')->from('team_user')
                    ->whereColumn('team_user.team_id', 'tasks.company_id')->whereColumn('team_user.user_id', 'tasks.assignee_id')))
            ->orderByRaw('tasks.due_date asc nulls last')
            ->get(['tasks.*', 'teams.name as company_name', 'teams.accounting_firm_id as company_firm_id']));

        // The accounting firm's brand for a user whose tasks are all in companies that show the same firm (ADR-030).
        $brands = FirmBranding::sharedFirmBrands(array_values($tasks->map(fn (Task $task): array => [
            'user_id' => (int) $task->assignee_id,
            'company_id' => (int) $task->company_id,
            'company_name' => (string) $task->getAttribute('company_name'),
            'firm_id' => $task->getAttribute('company_firm_id') === null ? null : (int) $task->getAttribute('company_firm_id'),
        ])->all()));

        $sent = 0;

        foreach ($tasks->groupBy('assignee_id') as $userId => $own) {
            $user = $users->get($userId);

            if (! $user instanceof User) {
                continue;
            }

            $this->info("Skickar sammanfattningen till användare {$user->id}...");
            Mail::to($user->email)->send(new TaskDigestMail($user->name, array_values($own->map(fn (Task $task): array => [
                'company' => (string) $task->getAttribute('company_name'),
                'title' => $task->title,
                'due' => $task->due_date?->toDateString(),
                'overdue' => $task->due_date !== null && $task->due_date->lessThan($today),
                'status' => $task->status->label(),
            ])->all()), $brands[$user->id] ?? null));
            $sent++;
        }

        $this->comment("{$sent} sammanfattningar skickade.");

        return self::SUCCESS;
    }
}
