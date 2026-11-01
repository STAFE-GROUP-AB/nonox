<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Actions\Tasks\WorkOnTask;
use App\Domain\Tasks\TaskStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The company's tasks (/uppgifter). With tasks:view every task of the
 * company; otherwise only those assigned to the user, such as what the
 * accounting firm has asked the client for.
 */
class TaskBoard extends Component
{
    use ShowsToasts;
    use WithPagination;

    /** oppna (default), a status slug, or alla. */
    #[Url(as: 'visa', except: 'oppna')]
    public string $show = 'oppna';

    #[Url(as: 'ansvarig', except: '')]
    public string $assignee = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['show', 'assignee'], true)) {
            $this->resetPage();
        }
    }

    #[On('task-saved')]
    public function taskSaved(): void
    {
        $this->resetPage();
    }

    public function setStatus(int $taskId, string $status, WorkOnTask $work): void
    {
        $task = Task::query()->findOrFail($taskId);
        $case = TaskStatus::tryFrom($status) ?? abort(404);

        try {
            $work->status($this->user(), $task, $case);
        } catch (AuthorizationException) {
            $this->toast(__('Du kan inte ändra den här uppgiften.'), 'error');

            return;
        }

        $this->toast(__('”:title” är nu: :status.', ['title' => $task->title, 'status' => $case->label()]));
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $seesAll = $user->hasTeamPermission($company, 'tasks:view');
        $status = TaskStatus::fromSlug($this->show);

        $tasks = Task::query()
            ->with(['assignee:id,name'])
            ->withCount(['checklistItems', 'checklistItems as checklist_done_count' => fn ($query) => $query->whereNotNull('done_at'), 'comments'])
            ->when(! $seesAll, fn ($query) => $query->where('assignee_id', $user->id))
            ->when($this->show === 'oppna', fn ($query) => $query->open())
            ->when($status instanceof TaskStatus, fn ($query) => $query->where('status', $status?->value))
            ->when($this->assignee === 'ingen', fn ($query) => $query->whereNull('assignee_id'))
            ->when(ctype_digit($this->assignee), fn ($query) => $query->where('assignee_id', (int) $this->assignee))
            ->orderByRaw("case when status = 'DONE' then 1 else 0 end")
            ->orderByRaw('due_date asc nulls last')
            ->orderByRaw("case priority when 'HIGH' then 0 when 'NORMAL' then 1 else 2 end")
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.tasks.task-board', [
            'company' => $company,
            'tasks' => $tasks,
            'seesAll' => $seesAll,
            'canManage' => $user->hasTeamPermission($company, 'tasks:manage'),
            'members' => $seesAll ? TaskForm::members($company) : [],
            'statuses' => TaskStatus::cases(),
        ]);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
