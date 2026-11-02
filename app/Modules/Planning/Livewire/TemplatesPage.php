<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\ApplyTemplate;
use App\Modules\Planning\Actions\SaveTemplate;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Skill;
use App\Modules\Planning\Models\Template;
use App\Modules\Planning\Models\TemplateShift;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Planering → Mallar: templates of one to eight weeks, their shifts, and
 * laying a template onto the schedule as drafts.
 */
class TemplatesPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    #[Url(as: 'mall')]
    public ?int $selected = null;

    /** null: no form; 0: new template; otherwise the template being renamed */
    public ?int $editing = null;

    /** @var array{name: string, weeks: int, notes: string} */
    public array $form = ['name' => '', 'weeks' => 1, 'notes' => ''];

    /** @var array<string, mixed> */
    public array $shift = [];

    /** @var array{week: string, times: int, people: bool} */
    public array $apply = ['week' => '', 'times' => 1, 'people' => true];

    public function mount(): void
    {
        abort_unless($this->allowed('planning:view'), 403);
        $this->resetShift();
        $this->apply['week'] = CarbonImmutable::today(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->toDateString();
    }

    public function create(): void
    {
        $this->editing = 0;
        $this->form = ['name' => '', 'weeks' => 1, 'notes' => ''];
    }

    public function edit(int $id): void
    {
        $template = Template::query()->findOrFail($id);
        $this->editing = $template->id;
        $this->form = ['name' => $template->name, 'weeks' => $template->weeks, 'notes' => (string) $template->notes];
    }

    public function save(SaveTemplate $save): void
    {
        $template = $this->editing ? Template::query()->findOrFail($this->editing) : null;

        try {
            $saved = $save($this->user(), $this->company(), $this->form, $template);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->selected = $saved->id;
        $this->editing = null;
        $this->toast($template === null ? __('Mallen är skapad. Lägg till dess pass.') : __('Mallen är sparad.'));
    }

    public function select(int $id): void
    {
        $this->selected = Template::query()->findOrFail($id)->id;
        $this->resetShift();
    }

    public function addShift(SaveTemplate $save): void
    {
        $template = Template::query()->findOrFail($this->selected);

        try {
            $save->saveShift($this->user(), $this->company(), $template, array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->shift));
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["shift.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast(__('Passet är tillagt i mallen.'));
        $this->resetShift(keepDay: true);
    }

    public function removeShift(int $id, SaveTemplate $save): void
    {
        $save->deleteShift($this->user(), $this->company(), TemplateShift::query()->findOrFail($id));
        $this->toast(__('Passet är borttaget ur mallen.'));
    }

    public function applyTemplate(ApplyTemplate $apply): void
    {
        $template = Template::query()->with('shifts')->findOrFail($this->selected);

        try {
            $created = $apply($this->user(), $this->company(), $template, CarbonImmutable::parse($this->apply['week'], LocalTimestamp::ZONE), (int) $this->apply['times'], (bool) $this->apply['people']);
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        } catch (Throwable) {
            $this->toast(__('Välj veckan mallen ska börja.'), 'error');

            return;
        }

        $this->toast(trans_choice('{0} Inga nya pass: mallens pass finns redan.|{1} 1 pass från mallen är tillagt som utkast.|[2,*] :count pass från mallen är tillagda som utkast.', $created, ['count' => $created]));
    }

    public function delete(int $id, SaveTemplate $save): void
    {
        $save->delete($this->user(), $this->company(), Template::query()->findOrFail($id));
        $this->selected = null;
        $this->toast(__('Mallen är borttagen. Pass som redan lagts ut finns kvar.'));
    }

    public function render(): View
    {
        $templates = Template::query()->withCount('shifts')->orderBy('name')->get();
        $current = $this->selected === null ? null : Template::query()->with('shifts')->find($this->selected);

        return view('planning::livewire.templates', [
            'templates' => $templates,
            'current' => $current,
            'areas' => Area::query()->whereNull('archived_at')->orderBy('sort')->orderBy('name')->pluck('name', 'id'),
            'skills' => Skill::query()->orderBy('name')->pluck('name', 'id'),
            'employees' => Employee::query()->where('status', '<>', 'TERMINATED')->orderBy('first_name')->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->first_name} {$employee->last_name}"]),
            'canManage' => $this->allowed('planning:manage'),
        ]);
    }

    private function resetShift(bool $keepDay = false): void
    {
        $this->shift = [
            'week_index' => $keepDay ? ($this->shift['week_index'] ?? 0) : 0,
            'weekday' => $keepDay ? ($this->shift['weekday'] ?? 1) : 1,
            'start' => $keepDay ? ($this->shift['start'] ?? '08:00') : '08:00',
            'end' => $keepDay ? ($this->shift['end'] ?? '16:00') : '16:00',
            'break_minutes' => 0,
            'employee_id' => null,
            'area_id' => null,
            'skill_id' => null,
            'note' => '',
        ];
    }
}
