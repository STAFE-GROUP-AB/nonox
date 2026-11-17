<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Planning\Actions\SaveTaskTemplate;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Skill;
use App\Modules\Planning\Models\TaskTemplate;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Planering → Uppgifter (ADR-037 gap 4): the recurring tasks that are put on
 * shifts by area, skill, weekday and time of day. The task text must not hold
 * personal data. All logic is in SaveTaskTemplate.
 */
class TaskTemplatesPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    /** null: no form open; 0: a new template; otherwise the template being edited */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->allowed('planning:view'), 403);
    }

    public function create(): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $this->resetErrorBag();
        $this->editing = 0;
        $this->form = ['title' => '', 'instructions' => '', 'area_id' => null, 'skill_id' => null, 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'due_time' => '', 'valid_from' => '', 'valid_to' => '', 'is_active' => true];
    }

    public function edit(int $id): void
    {
        abort_unless($this->allowed('planning:manage'), 403);
        $template = TaskTemplate::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->editing = $template->id;
        $this->form = [
            'title' => $template->title,
            'instructions' => (string) $template->instructions,
            'area_id' => $template->area_id,
            'skill_id' => $template->skill_id,
            'weekdays' => array_map('intval', $template->weekdays),
            'due_time' => (string) $template->due_time,
            'valid_from' => $template->valid_from?->toDateString() ?? '',
            'valid_to' => $template->valid_to?->toDateString() ?? '',
            'is_active' => $template->is_active,
        ];
    }

    public function save(SaveTaskTemplate $save): void
    {
        $template = $this->editing ? TaskTemplate::query()->findOrFail($this->editing) : null;
        $form = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form);

        try {
            $result = $save($this->user(), $this->company(), $form, $template);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->editing = null;
        $this->toast(trans_choice('{0} Uppgiften är sparad. Den hamnar på de pass den passar.|{1} Uppgiften är sparad och lagd på 1 pass.|[2,*] Uppgiften är sparad och lagd på :count pass.', $result['added'], ['count' => $result['added']]));
    }

    public function delete(int $id, SaveTaskTemplate $save): void
    {
        $save->delete($this->user(), $this->company(), TaskTemplate::query()->findOrFail($id));
        $this->editing = null;
        $this->toast(__('Uppgiften är borttagen från mallarna och från pass som inte har börjat.'));
    }

    public function render(): View
    {
        return view('planning::livewire.task-templates', [
            'templates' => TaskTemplate::query()->orderByDesc('is_active')->orderBy('title')->get(),
            'areas' => Area::query()->orderBy('sort')->orderBy('name')->get(['id', 'name', 'archived_at']),
            'skills' => Skill::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $this->allowed('planning:manage'),
        ]);
    }
}
