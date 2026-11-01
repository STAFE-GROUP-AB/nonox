<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Actions\Tasks\SaveRecurrence;
use App\Domain\Tasks\TaskFrequency;
use App\Domain\Tasks\TaskType;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\TaskRecurrence;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Återkommande uppgifter (/uppgifter/aterkommande): tasks that come back
 * every month, quarter or year, such as "Moms varje månad". Needs
 * tasks:manage.
 */
class RecurringTasks extends Component
{
    use ShowsToasts;

    public string $title = '';

    public string $description = '';

    public string $type = 'VAT';

    public string $frequency = 'MONTHLY';

    public string $dueDay = '12';

    public string $assigneeId = '';

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'tasks:manage'), 404);
    }

    public function save(SaveRecurrence $save): void
    {
        $recurrence = $save($this->user(), $this->company(), [
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'frequency' => $this->frequency,
            'due_day' => $this->dueDay,
            'assignee_id' => $this->assigneeId === '' ? null : (int) $this->assigneeId,
        ]);

        $this->reset('title', 'description', 'assigneeId');
        $this->toast(__('”:title” kommer nu :frequency. Periodens uppgift är skapad.', ['title' => $recurrence->title, 'frequency' => mb_strtolower($recurrence->frequency->label())]));
    }

    public function stop(int $recurrenceId, SaveRecurrence $save): void
    {
        $save->stop($this->user(), TaskRecurrence::query()->findOrFail($recurrenceId));
        $this->toast(__('Den återkommande uppgiften är stoppad. Redan skapade uppgifter finns kvar.'));
    }

    public function render(): View
    {
        $company = $this->company();

        return view('livewire.tasks.recurring-tasks', [
            'recurrences' => TaskRecurrence::query()->with('assignee:id,name')->orderByDesc('active')->orderBy('title')->get(),
            'members' => TaskForm::members($company),
            'types' => TaskType::cases(),
            'frequencies' => TaskFrequency::cases(),
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
