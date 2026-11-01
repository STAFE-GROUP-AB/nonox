<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Domain\Firm\Memberships;
use App\Domain\Tasks\TaskStatus;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Mina uppgifter (/mina-uppgifter): the user's tasks in every company they
 * belong to — assigned to them, or created by them and handed to someone
 * else. Each opens in its own company. The daily e-mail is switched on
 * here.
 */
class MyTasks extends Component implements SpansCompanies
{
    use ShowsToasts;

    /** tilldelade: assigned to me; skapade: created by me for others; klara: done lately. */
    #[Url(as: 'visa', except: 'tilldelade')]
    public string $show = 'tilldelade';

    public string $digest = 'NONE';

    public function mount(): void
    {
        $this->digest = $this->user()->task_digest === 'DAILY' ? 'DAILY' : 'NONE';
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

    public function render(): View
    {
        $user = $this->user();
        $memberships = Memberships::of($user);
        $ids = $memberships->ids();
        $names = $memberships->companies->mapWithKeys(fn ($company): array => [$company->id => $company->name])->all();

        // Only the user's own companies, taken from the memberships; within them only the user's own tasks.
        $tasks = $ids === [] ? collect() : CompanyScope::bypass(fn () => Task::query()
            ->whereIn('company_id', $ids)
            ->when($this->show === 'tilldelade', fn ($query) => $query->open()->where('assignee_id', $user->id))
            ->when($this->show === 'skapade', fn ($query) => $query->open()->where('created_by', $user->id)
                ->where(fn ($inner) => $inner->whereNull('assignee_id')->orWhere('assignee_id', '!=', $user->id)))
            ->when($this->show === 'klara', fn ($query) => $query->where('status', TaskStatus::Done->value)
                ->where(fn ($inner) => $inner->where('assignee_id', $user->id)->orWhere('created_by', $user->id))
                ->where('completed_at', '>=', now()->subDays(30)))
            ->with('assignee:id,name')
            ->orderByRaw('due_date asc nulls last')->orderByDesc('id')
            ->limit(200)
            ->get());

        return view('livewire.tasks.my-tasks', [
            'tasks' => $tasks,
            'names' => $names,
            'canCreate' => $memberships->where('tasks:manage') !== [],
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
