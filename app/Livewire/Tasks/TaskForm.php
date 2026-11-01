<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Actions\Tasks\SaveTask;
use App\Domain\Firm\Memberships;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Laravel\Jetstream\Jetstream;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Ny uppgift": on Mina företag the user picks the company, on a company's
 * task list the company is fixed. The company is always one of the user's
 * own where they hold tasks:manage; SaveTask checks it again.
 */
class TaskForm extends Component implements SpansCompanies
{
    use ShowsToasts;

    #[Locked]
    public ?int $fixedCompanyId = null;

    public bool $open = false;

    public string $companyId = '';

    public string $title = '';

    public string $description = '';

    public string $type = 'OTHER';

    public string $priority = 'NORMAL';

    public string $dueDate = '';

    public string $assigneeId = '';

    public string $status = 'TODO';

    public string $checklist = '';

    public function mount(?int $companyId = null): void
    {
        $this->fixedCompanyId = $companyId;
        $this->companyId = $companyId === null ? '' : (string) $companyId;
    }

    public function updatedCompanyId(): void
    {
        $this->assigneeId = '';
    }

    public function save(SaveTask $save): void
    {
        $company = $this->company();

        if (! $company instanceof Company) {
            $this->addError('companyId', __('Välj ett företag.'));

            return;
        }

        try {
            $task = $save($this->user(), $company, [
                'title' => $this->title,
                'description' => $this->description,
                'type' => $this->type,
                'priority' => $this->priority,
                'status' => in_array($this->status, [TaskStatus::Todo->value, TaskStatus::WaitingForClient->value], true) ? $this->status : TaskStatus::Todo->value,
                'due_date' => $this->dueDate === '' ? null : $this->dueDate,
                'assignee_id' => $this->assigneeId === '' ? null : (int) $this->assigneeId,
                'checklist' => preg_split('/\R/u', $this->checklist) ?: [],
            ]);
        } catch (AuthorizationException) {
            $this->toast(__('Du kan inte skapa uppgifter i :company.', ['company' => $company->name]), 'error');

            return;
        }

        $this->reset('title', 'description', 'dueDate', 'assigneeId', 'checklist', 'open');
        $this->type = 'OTHER';
        $this->priority = 'NORMAL';
        $this->status = 'TODO';
        $this->dispatch('task-saved', taskId: $task->id);
        $this->toast(__('Uppgiften ”:title” är skapad i :company.', ['title' => $task->title, 'company' => $company->name]));
    }

    public function render(): View
    {
        $memberships = Memberships::of($this->user());
        $allowed = $memberships->where('tasks:manage');
        $companies = $memberships->companies->filter(fn (Company $company): bool => in_array($company->id, $allowed, true))->values();
        $company = $this->company($memberships);

        return view('livewire.tasks.task-form', [
            'companies' => $companies,
            'company' => $company,
            'members' => $company instanceof Company ? self::members($company) : [],
            'types' => TaskType::cases(),
            'priorities' => TaskPriority::cases(),
            'canCreate' => $allowed !== [] && ($this->fixedCompanyId === null || in_array($this->fixedCompanyId, $allowed, true)),
        ]);
    }

    /**
     * The owner and members of a company, the people a task can go to, with
     * their role there. One query.
     *
     * @return array<int, string> user id => "Name (role)"
     */
    public static function members(Company $company): array
    {
        $rows = DB::table('users')
            ->leftJoin('team_user', fn ($join) => $join->on('team_user.user_id', '=', 'users.id')->where('team_user.team_id', $company->id))
            ->where(fn ($query) => $query->where('users.id', $company->user_id)->orWhereNotNull('team_user.user_id'))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'team_user.role']);
        $roles = collect(Jetstream::$roles)->mapWithKeys(fn ($role): array => [$role->key => $role->name])->all();

        return $rows->mapWithKeys(fn (object $row): array => [(int) $row->id => $row->role === null
            ? "{$row->name} (".__('Ägare').')'
            : "{$row->name} (".($roles[$row->role] ?? $row->role).')'])->all();
    }

    private function company(?Memberships $memberships = null): ?Company
    {
        $id = (int) $this->companyId;

        if ($id === 0) {
            return null;
        }

        $memberships ??= Memberships::of($this->user());

        // A fresh model: one taken from the list of companies may not lazy load its members.
        return $memberships->may($id, 'tasks:manage') ? Company::query()->find($id) : null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
