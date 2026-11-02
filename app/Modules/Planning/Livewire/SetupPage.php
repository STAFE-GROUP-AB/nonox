<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveSkill;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\Skill;
use App\Modules\StaffLedger\Livewire\Concerns\InCurrentCompany;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\StaffLedgerModule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Planering → Inställningar: the areas shifts are planned in, with colours
 * and an optional staff-ledger location, the skills shifts can ask for, and
 * which employees have them. The rule set and notifications are
 * PlanningSettingsForm, on the same page.
 */
class SetupPage extends Component
{
    use InCurrentCompany;
    use ShowsToasts;

    public const array COLOURS = ['#e4572e', '#2e86ab', '#3bb273', '#f4a259', '#8e6c8a', '#c9a227', '#5c6b73', '#d1495b'];

    /** null: no form open; 0: a new area; otherwise the area being edited */
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public string $skillName = '';

    public ?int $renaming = null;

    public string $renameTo = '';

    public function mount(): void
    {
        abort_unless($this->allowed('planning:view'), 403);
    }

    public function createArea(): void
    {
        $this->editing = 0;
        $this->form = ['name' => '', 'colour' => self::COLOURS[Area::query()->count() % count(self::COLOURS)], 'location_id' => null, 'sort' => Area::query()->max('sort') + 1];
    }

    public function editArea(int $id): void
    {
        $area = Area::query()->findOrFail($id);
        $this->editing = $area->id;
        $this->form = $area->only(['name', 'colour', 'location_id', 'sort']);
    }

    public function saveArea(SaveArea $save): void
    {
        $area = $this->editing ? Area::query()->findOrFail($this->editing) : null;

        try {
            $save($this->user(), $this->company(), array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $this->form), $area);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["form.{$key}" => $messages])->all());
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->toast($area === null ? __('Avdelningen är tillagd.') : __('Avdelningen är sparad.'));
        $this->reset('editing', 'form');
    }

    public function archiveArea(int $id, SaveArea $save): void
    {
        $area = $save->archive($this->user(), $this->company(), Area::query()->findOrFail($id));
        $this->toast($area->archived_at ? __('Avdelningen är arkiverad. Passen behåller den.') : __('Avdelningen är aktiv igen.'));
    }

    public function addSkill(SaveSkill $save): void
    {
        try {
            $save($this->user(), $this->company(), $this->skillName);
        } catch (ValidationException $exception) {
            $this->addError('skillName', $this->firstError($exception));
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->skillName = '';
        $this->toast(__('Kompetensen är tillagd.'));
    }

    public function renameSkill(int $id): void
    {
        $this->renaming = $id;
        $this->renameTo = (string) Skill::query()->findOrFail($id)->name;
    }

    public function saveSkillName(SaveSkill $save): void
    {
        try {
            $save($this->user(), $this->company(), $this->renameTo, Skill::query()->findOrFail($this->renaming));
        } catch (ValidationException $exception) {
            $this->toast($this->firstError($exception), 'error');

            return;
        }

        $this->reset('renaming', 'renameTo');
        $this->toast(__('Kompetensen är sparad.'));
    }

    public function deleteSkill(int $id, SaveSkill $save): void
    {
        $save->delete($this->user(), $this->company(), Skill::query()->findOrFail($id));
        $this->toast(__('Kompetensen är borttagen från personal och pass.'));
    }

    public function toggleSkill(int $employeeId, int $skillId, SaveSkill $save): void
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $current = EmployeeSkill::query()->where('employee_id', $employee->id)->pluck('skill_id')->map(fn (mixed $id): int => (int) $id)->all();
        $next = array_values(in_array($skillId, $current, true) ? array_diff($current, [$skillId]) : [...$current, $skillId]);

        $save->assign($this->user(), $this->company(), $employee, $next);
        $this->toast(__('Kompetenserna för :name är sparade.', ['name' => $employee->first_name]));
    }

    public function render(ModuleManager $modules): View
    {
        return view('planning::livewire.setup', [
            'areas' => Area::query()->orderByRaw('archived_at is not null')->orderBy('sort')->orderBy('name')->get(),
            'skills' => Skill::query()->orderBy('name')->get(),
            'employees' => Employee::query()->where('status', '<>', 'TERMINATED')->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'employeeSkills' => EmployeeSkill::query()->get(['employee_id', 'skill_id'])->map(fn (EmployeeSkill $row): string => "{$row->employee_id}|{$row->skill_id}")->flip(),
            'locations' => $modules->isEnabled(StaffLedgerModule::KEY, $this->company()) ? Location::query()->whereNull('archived_at')->orderBy('name')->pluck('name', 'id') : collect(),
            'canManage' => $this->allowed('planning:manage'),
            'colours' => self::COLOURS,
        ]);
    }
}
