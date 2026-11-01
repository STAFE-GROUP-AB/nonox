<?php

declare(strict_types=1);

namespace App\Livewire\Tasks;

use App\Actions\Tasks\SaveTask;
use App\Actions\Tasks\WorkOnTask;
use App\Domain\Tasks\TaskLinks;
use App\Domain\Tasks\TaskPeople;
use App\Domain\Tasks\TaskPriority;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * One task: status, checklist, comments, the linked record, and for those
 * with tasks:manage the details and the assignee. Company-scoped: another
 * company's task is not found.
 */
class TaskDetail extends Component
{
    use ShowsToasts;

    public Task $task;

    public bool $editing = false;

    public string $title = '';

    public string $description = '';

    public string $type = 'OTHER';

    public string $priority = 'NORMAL';

    public string $dueDate = '';

    public string $assigneeId = '';

    public string $subjectType = '';

    public string $subjectId = '';

    public string $comment = '';

    public string $checklistLabel = '';

    public bool $confirmingDelete = false;

    public function mount(Task $task): void
    {
        abort_unless($this->user()->can('view', $task), 404);
        $this->task = $task;
    }

    public function edit(): void
    {
        abort_unless($this->user()->can('update', $this->task), 403);
        $this->fill([
            'editing' => true,
            'title' => $this->task->title,
            'description' => (string) $this->task->description,
            'type' => $this->task->type->value,
            'priority' => $this->task->priority->value,
            'dueDate' => (string) $this->task->due_date?->toDateString(),
            'assigneeId' => (string) $this->task->assignee_id,
            'subjectType' => (string) $this->task->subject_type,
            'subjectId' => (string) $this->task->subject_id,
        ]);
    }

    public function updatedSubjectType(): void
    {
        $this->subjectId = '';
    }

    public function save(SaveTask $save): void
    {
        $save($this->user(), $this->company(), [
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'priority' => $this->priority,
            'due_date' => $this->dueDate === '' ? null : $this->dueDate,
            'assignee_id' => $this->assigneeId === '' ? null : (int) $this->assigneeId,
            'subject_type' => $this->subjectType === '' ? null : $this->subjectType,
            'subject_id' => $this->subjectType === '' ? null : $this->subjectId,
        ], $this->task);

        $this->editing = false;
        $this->task->refresh();
        $this->toast(__('Uppgiften är sparad.'));
    }

    public function setStatus(string $status, WorkOnTask $work): void
    {
        $case = TaskStatus::tryFrom($status) ?? abort(404);

        if ($this->guard(fn () => $work->status($this->user(), $this->task, $case))) {
            $this->toast(__('Status: :status.', ['status' => $case->label()]));
        }
    }

    public function addComment(WorkOnTask $work): void
    {
        if ($this->guard(fn () => $work->comment($this->user(), $this->task, $this->comment))) {
            $this->comment = '';
            $this->toast(__('Kommentaren är sparad.'));
        }
    }

    public function addChecklistItem(WorkOnTask $work): void
    {
        if ($this->guard(fn () => $work->addChecklistItem($this->user(), $this->task, $this->checklistLabel))) {
            $this->checklistLabel = '';
            $this->toast(__('Punkten är tillagd.'));
        }
    }

    public function toggleChecklistItem(int $itemId, WorkOnTask $work): void
    {
        $done = false;

        if ($this->guard(function () use ($work, $itemId, &$done): void {
            $done = $work->toggleChecklistItem($this->user(), $this->task, $itemId)->done_at !== null;
        })) {
            $this->toast($done ? __('Punkten är avbockad.') : __('Punkten är inte längre avbockad.'));
        }
    }

    public function removeChecklistItem(int $itemId, WorkOnTask $work): void
    {
        if ($this->guard(fn () => $work->removeChecklistItem($this->user(), $this->task, $itemId))) {
            $this->toast(__('Punkten är borttagen.'));
        }
    }

    public function delete(WorkOnTask $work): void
    {
        $work->delete($this->user(), $this->task);
        Toast::flash(__('Uppgiften är borttagen.'));
        $this->redirectRoute('tasks.index', ['foretag' => $this->task->company_id, 'visa' => 'alla']);
    }

    public function render(): View
    {
        $user = $this->user();
        $this->task->load(['assignee:id,name', 'creator:id,name', 'checklistItems', 'comments.user:id,name']);
        $canManage = $user->can('update', $this->task);

        return view('livewire.tasks.task-detail', [
            'canManage' => $canManage,
            'canWork' => $canManage || $user->can('work', $this->task),
            'statuses' => TaskStatus::cases(),
            'types' => TaskType::cases(),
            'priorities' => TaskPriority::cases(),
            'people' => $this->editing ? TaskPeople::of($this->company()) : ['firm' => [], 'client' => []],
            'linkTypes' => array_keys(TaskLinks::TYPES),
            'linkOptions' => $this->editing && $this->subjectType !== '' ? TaskLinks::options($this->subjectType) : [],
            'linkLabel' => TaskLinks::label($this->task),
            'linkUrl' => TaskLinks::url($this->task),
        ]);
    }

    /** Runs a change; a refusal becomes an error toast. */
    private function guard(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (AuthorizationException) {
            $this->toast(__('Du kan inte ändra den här uppgiften.'), 'error');

            return false;
        }
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->task->company_id);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
