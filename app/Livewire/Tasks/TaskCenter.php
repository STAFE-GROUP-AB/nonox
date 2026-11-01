<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Actions\Tasks\WorkOnTask;
use App\Domain\Firm\Memberships;
use App\Domain\Tasks\TaskPeople;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\ClientProfile;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\SpansCompanies;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Uppgifter (/uppgifter, ADR-028): every task the user may see in every
 * company they belong to — their own, what waits for the client, what they
 * handed to others, and for the firm all clients' tasks. Each task opens in
 * its own company. One page of tasks is a fixed number of queries, whatever
 * the number of companies.
 */
class TaskCenter extends Component implements SpansCompanies
{
    use ShowsToasts;
    use WithPagination;

    public const array VIEWS = ['mina', 'alla', 'vantar', 'lamnade', 'klara'];

    #[Url(as: 'visa', except: 'mina')]
    public string $view = 'mina';

    #[Url(as: 'foretag', except: '')]
    public string $company = '';

    /** byran, kunden or ingen: who the task is with. */
    #[Url(as: 'hos', except: '')]
    public string $side = '';

    #[Url(as: 'frist', except: '')]
    public string $deadline = '';

    #[Url(as: 'typ', except: '')]
    public string $type = '';

    #[Url(as: 'sok', except: '')]
    public string $search = '';

    public string $digest = 'NONE';

    public function mount(): void
    {
        $this->digest = $this->user()->task_digest === 'DAILY' ? 'DAILY' : 'NONE';

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'mina';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['view', 'company', 'side', 'deadline', 'type', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function updatedDigest(): void
    {
        $value = $this->digest === 'DAILY' ? 'DAILY' : 'NONE';
        $this->user()->forceFill(['task_digest' => $value])->save();
        $this->toast($value === 'DAILY'
            ? __('Du får en sammanfattning med e-post varje vardag.')
            : __('Den dagliga sammanfattningen är avstängd.'));
    }

    #[On('task-saved')]
    public function taskSaved(): void {}

    public function clearFilters(): void
    {
        $this->reset('company', 'side', 'deadline', 'type', 'search');
        $this->resetPage();
    }

    /** Marks a task done, or opens it again, straight from the list. */
    public function setStatus(int $taskId, string $status, WorkOnTask $work): void
    {
        $memberships = Memberships::of($this->user());
        // The task is looked up among the user's own companies only.
        $task = CompanyScope::bypass(fn () => Task::query()->whereIn('company_id', $memberships->ids())->find($taskId)) ?? abort(404);
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
        $memberships = Memberships::of($user);
        $ids = $memberships->ids();
        $viewIds = $memberships->where('tasks:view');
        $manageIds = $memberships->where('tasks:manage');
        $names = $memberships->companies->mapWithKeys(fn ($company): array => [$company->id => (string) $company->name])->all();
        $today = CarbonImmutable::today();

        // Only the user's own companies, from their memberships; within them what the user may see.
        $visible = fn (): Builder => Task::query()->whereIn('tasks.company_id', $ids)
            ->where(fn (Builder $query) => $query->whereIn('tasks.company_id', $viewIds)->orWhere('tasks.assignee_id', $user->id));

        $counts = $ids === [] ? null : CompanyScope::bypass(fn () => $visible()->toBase()
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?, ?) AND assignee_id = ?) AS mina', [...TaskStatus::openValues(), $user->id])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?, ?)) AS alla', TaskStatus::openValues())
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS vantar', [TaskStatus::WaitingForClient->value])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?, ?) AND created_by = ? AND (assignee_id IS NULL OR assignee_id <> ?)) AS lamnade', [...TaskStatus::openValues(), $user->id, $user->id])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?, ?) AND due_date < ?) AS forsenade', [...TaskStatus::openValues(), $today->toDateString()])
            ->first());

        $tasks = CompanyScope::bypass(fn () => $this->filtered($visible(), $user, $today)
            ->with('assignee:id,name')
            ->withCount(['checklistItems', 'checklistItems as checklist_done_count' => fn ($query) => $query->whereNotNull('done_at'), 'comments'])
            ->orderByRaw("case when status = 'DONE' then 1 else 0 end")
            ->orderByRaw($this->view === 'klara' ? 'completed_at desc' : 'due_date asc nulls last')
            ->orderByRaw("case priority when 'HIGH' then 0 when 'NORMAL' then 1 else 2 end")
            ->orderByDesc('id')
            ->paginate(40));

        $pairs = [];

        foreach ($tasks as $task) {
            if ($task->assignee_id !== null) {
                $pairs[] = [$task->company_id, $task->assignee_id];
            }
        }

        return view('livewire.tasks.task-center', [
            'tasks' => $tasks,
            'names' => $names,
            'counts' => [
                'mina' => (int) ($counts->mina ?? 0),
                'alla' => (int) ($counts->alla ?? 0),
                'vantar' => (int) ($counts->vantar ?? 0),
                'lamnade' => (int) ($counts->lamnade ?? 0),
                'forsenade' => (int) ($counts->forsenade ?? 0),
            ],
            'sides' => TaskPeople::sides($pairs),
            'manageIds' => $manageIds,
            'seesOthers' => $viewIds !== [],
            'canCreate' => $manageIds !== [],
            'types' => TaskType::cases(),
            'filtered' => $this->company !== '' || $this->side !== '' || $this->deadline !== '' || $this->type !== '' || $this->search !== '',
            'today' => $today,
        ]);
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    private function filtered(Builder $query, User $user, CarbonImmutable $today): Builder
    {
        match ($this->view) {
            'alla' => $query->open(),
            'vantar' => $query->where('status', TaskStatus::WaitingForClient->value),
            'lamnade' => $query->open()->where('created_by', $user->id)->where(fn (Builder $inner) => $inner->whereNull('assignee_id')->orWhere('assignee_id', '!=', $user->id)),
            'klara' => $query->where('status', TaskStatus::Done->value)->where('completed_at', '>=', now()->subDays(30)),
            default => $query->open()->where('assignee_id', $user->id),
        };

        if (ctype_digit($this->company)) {
            $query->where('tasks.company_id', (int) $this->company);
        }

        $type = TaskType::fromSlug($this->type);

        if ($type instanceof TaskType) {
            $query->where('type', $type->value);
        }

        match ($this->deadline) {
            'forsenad' => $query->whereDate('due_date', '<', $today->toDateString()),
            'vecka' => $query->whereDate('due_date', '<=', $today->endOfWeek()->toDateString()),
            'manad' => $query->whereDate('due_date', '<=', $today->endOfMonth()->toDateString()),
            default => null,
        };

        $firm = fn ($exists) => $exists->selectRaw('1')->from('team_user')->whereColumn('team_user.team_id', 'tasks.company_id')
            ->whereColumn('team_user.user_id', 'tasks.assignee_id')->where('team_user.role', ClientProfile::FIRM_ROLE);

        match ($this->side) {
            'byran' => $query->whereExists($firm),
            'kunden' => $query->whereNotNull('assignee_id')->whereNotExists($firm),
            'ingen' => $query->whereNull('assignee_id'),
            default => null,
        };

        $needle = trim($this->search);

        if ($needle !== '') {
            $query->where('title', 'ilike', '%'.addcslashes($needle, '%_\\').'%');
        }

        return $query;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
