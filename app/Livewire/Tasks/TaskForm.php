<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Actions\Tasks\SaveTask;
use App\Domain\Firm\Memberships;
use App\Domain\Tasks\TaskPeople;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Ny uppgift": the user picks one of their companies where they hold
 * tasks:manage (or the company is fixed), and the person, grouped as Byrån
 * and Kunden. Handing a task from the firm to the client makes it "Väntar på
 * kund"; handing it back makes it "Att göra". SaveTask checks everything
 * again.
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

    public function start(): void
    {
        $this->open = true;
        $allowed = Memberships::of($this->user())->where('tasks:manage');

        // One company to choose from, or the one the user works in now: chosen already.
        if ($this->companyId === '') {
            $current = $this->user()->current_team_id;
            $this->companyId = (string) (count($allowed) === 1 ? $allowed[0] : (in_array($current, $allowed, true) ? $current : ''));
        }
    }

    public function updatedCompanyId(): void
    {
        $this->assigneeId = '';
    }

    public function updatedAssigneeId(): void
    {
        $company = $this->company();

        if (! $company instanceof Company || $this->assigneeId === '') {
            return;
        }

        $mine = TaskPeople::sideOf($company, $this->user()->id);
        $theirs = TaskPeople::sideOf($company, (int) $this->assigneeId);

        if ($mine === TaskPeople::FIRM && $theirs === TaskPeople::CLIENT) {
            $this->status = TaskStatus::WaitingForClient->value;
        } elseif ($theirs === TaskPeople::FIRM) {
            $this->status = TaskStatus::Todo->value;
        }
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
        $company = $this->open ? $this->company($memberships) : null;

        return view('livewire.tasks.task-form', [
            'companies' => $companies,
            'company' => $company,
            'people' => $company instanceof Company ? TaskPeople::of($company) : ['firm' => [], 'client' => []],
            'types' => TaskType::cases(),
            'priorities' => TaskPriority::cases(),
            'canCreate' => $allowed !== [] && ($this->fixedCompanyId === null || in_array($this->fixedCompanyId, $allowed, true)),
        ]);
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
