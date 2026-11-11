<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Planning;

use App\Modules\Manufacturing\Actions\Planning\SaveDowntime;
use App\Modules\Manufacturing\Actions\Planning\SavePlanningPerson;
use App\Modules\Manufacturing\Actions\Planning\SavePlanningSettings;
use App\Modules\Manufacturing\Actions\Planning\SaveSkill;
use App\Modules\Manufacturing\Actions\Planning\SaveWorkstation;
use App\Modules\Manufacturing\Domain\DowntimeReason;
use App\Modules\Manufacturing\Livewire\Concerns\ManufacturingPage;
use App\Modules\Manufacturing\Models\Downtime;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\ProductionSkill;
use App\Modules\Manufacturing\Models\WorkPattern;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanningSettings;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\PlanningModule;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tillverkning → Planering → Inställningar (ADR-071 §13.2): workstations
 * with the operations they serve and their downtime, the people with their
 * skills and working week, the list of skills, and the company's working
 * hours and planning horizon. Changes need manufacturing:manage, downtime
 * production:plan.
 */
class SetupPage extends Component
{
    use ManufacturingPage;

    #[Url(as: 'flik')]
    public string $tab = 'stationer';

    #[Locked]
    public ?int $stationId = null;

    public bool $editingStation = false;

    /** @var array{name: string, code: string, operation_ids: list<string>} */
    public array $station = ['name' => '', 'code' => '', 'operation_ids' => []];

    /** @var array{workstation_id: string, starts_at: string, ends_at: string, reason: string} */
    public array $downtime = ['workstation_id' => '', 'starts_at' => '', 'ends_at' => '', 'reason' => 'BREAKDOWN'];

    #[Locked]
    public ?int $personId = null;

    /** @var array{skill_ids: list<string>, own_week: bool, days: array<int, array{start: string, end: string, break_start: string, break_minutes: string}>} */
    public array $person = ['skill_ids' => [], 'own_week' => false, 'days' => []];

    public string $skillName = '';

    #[Locked]
    public ?int $skillId = null;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
        $this->loadSettings();
    }

    public function newStation(): void
    {
        abort_unless($this->can('manage'), 403);
        $this->stationId = null;
        $this->station = ['name' => '', 'code' => '', 'operation_ids' => []];
        $this->editingStation = true;
    }

    public function editStation(int $id): void
    {
        abort_unless($this->can('manage'), 403);
        $workstation = Workstation::query()->with('operations:id')->findOrFail($id);
        $this->stationId = $workstation->id;
        $this->station = ['name' => $workstation->name, 'code' => (string) $workstation->code, 'operation_ids' => array_values($workstation->operations->pluck('id')->map(fn ($id): string => (string) $id)->all())];
        $this->editingStation = true;
    }

    public function saveStation(SaveWorkstation $save): void
    {
        abort_unless($this->can('manage'), 403);
        $workstation = $this->stationId === null ? null : Workstation::query()->findOrFail($this->stationId);

        if ($this->attempt(fn () => $save($this->user(), $this->company(), $this->station, $workstation), __('Arbetsstationen är sparad.'), 'station.')) {
            $this->editingStation = false;
        }
    }

    public function archiveStation(int $id, bool $archived, SaveWorkstation $save): void
    {
        abort_unless($this->can('manage'), 403);
        $workstation = Workstation::query()->findOrFail($id);

        $this->attempt(fn () => $save->archive($this->user(), $this->company(), $workstation, $archived), $archived ? __('Arbetsstationen är arkiverad.') : __('Arbetsstationen används igen.'));
    }

    public function saveDowntime(SaveDowntime $save): void
    {
        abort_unless($this->can('plan'), 403);
        $workstation = Workstation::query()->findOrFail((int) $this->downtime['workstation_id']);

        if ($this->attempt(fn () => $save($this->user(), $this->company(), $workstation, $this->downtime), __('Stoppet är registrerat. Planen beräknas om och avvikelsen visar vad det gör med leveranserna.'), 'downtime.')) {
            $this->downtime = ['workstation_id' => '', 'starts_at' => '', 'ends_at' => '', 'reason' => 'BREAKDOWN'];
        }
    }

    public function endDowntime(int $id, SaveDowntime $save): void
    {
        abort_unless($this->can('plan'), 403);
        $downtime = Downtime::query()->findOrFail($id);

        $this->attempt(fn () => $save->end($this->user(), $this->company(), $downtime), __('Arbetsstationen är i drift igen.'));
    }

    public function editPerson(int $id): void
    {
        abort_unless($this->can('manage'), 403);
        $employee = Employee::query()->findOrFail($id);
        $patterns = WorkPattern::query()->where('employee_id', $employee->id)->get()->keyBy('weekday');
        $days = [];

        foreach (range(1, 7) as $weekday) {
            $row = $patterns->get($weekday);
            $days[$weekday] = $row === null
                ? ['start' => '', 'end' => '', 'break_start' => '', 'break_minutes' => '']
                : ['start' => $row->start_time, 'end' => $row->end_time, 'break_start' => (string) $row->break_start, 'break_minutes' => (string) $row->break_minutes];
        }

        $this->personId = $employee->id;
        $this->person = [
            'skill_ids' => array_values(DB::table('production_employee_skills')->where('company_id', $this->company()->id)->where('employee_id', $employee->id)->pluck('skill_id')->map(fn ($id): string => (string) $id)->all()),
            'own_week' => $patterns->isNotEmpty(),
            'days' => $days,
        ];
    }

    public function savePerson(SavePlanningPerson $save): void
    {
        abort_unless($this->can('manage'), 403);
        $employee = Employee::query()->findOrFail((int) $this->personId);

        if ($this->attempt(fn () => $save($this->user(), $this->company(), $employee, $this->person), __('Personens kompetenser och arbetstid är sparade.'), 'person.')) {
            $this->personId = null;
        }
    }

    public function cancelPerson(): void
    {
        $this->personId = null;
        $this->resetErrorBag();
    }

    public function editSkill(?int $id): void
    {
        abort_unless($this->can('manage'), 403);
        $this->skillId = $id;
        $this->skillName = $id === null ? '' : ProductionSkill::query()->findOrFail($id)->name;
    }

    public function saveSkill(SaveSkill $save): void
    {
        abort_unless($this->can('manage'), 403);
        $skill = $this->skillId === null ? null : ProductionSkill::query()->findOrFail($this->skillId);

        if ($this->attempt(fn () => $save($this->user(), $this->company(), $this->skillName, $skill), __('Kompetensen är sparad.'), 'skill.')) {
            $this->skillId = null;
            $this->skillName = '';
        }
    }

    public function deleteSkill(int $id, SaveSkill $save): void
    {
        abort_unless($this->can('manage'), 403);
        $skill = ProductionSkill::query()->findOrFail($id);

        $this->attempt(fn () => $save->delete($this->user(), $this->company(), $skill), __('Kompetensen är borttagen.'));
    }

    public function saveSettings(SavePlanningSettings $save): void
    {
        abort_unless($this->can('manage'), 403);

        $this->attempt(fn () => $save($this->user(), $this->company(), $this->settings), __('Arbetstider och planering är sparade. Planen beräknas om.'), 'settings.');
    }

    private function loadSettings(): void
    {
        $settings = PlanningSettings::for($this->company());
        $this->settings = [
            'working_days' => array_map('strval', $settings->workingDays()),
            'day_start' => Clock::timeOfDay($settings->dayStart()),
            'day_end' => Clock::timeOfDay($settings->dayEnd()),
            'break_start' => $settings->breakStart() === null ? '' : Clock::timeOfDay($settings->breakStart()),
            'break_minutes' => (string) $settings->breakMinutes(),
            'horizon_weeks' => (string) $settings->horizonWeeks(),
            'buffer_days' => (string) $settings->deliveryBufferDays(),
            'frozen_days' => (string) $settings->frozenDays(),
            'lead_time_days' => (string) $settings->leadTimeDays(),
        ];
    }

    public function render(): View
    {
        return view('manufacturing::livewire.planning.setup', [
            'canManage' => $this->can('manage'),
            'canPlan' => $this->can('plan'),
            'workstations' => Workstation::query()->with('operations:id,name')->orderBy('archived_at')->orderBy('name')->get(),
            'downtimes' => Downtime::query()->with('workstation:id,name')->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))->orderBy('starts_at')->get(),
            'operations' => Operation::query()->active()->orderBy('position')->orderBy('name')->get(['id', 'name']),
            'skills' => ProductionSkill::query()->withCount('employees')->orderBy('name')->get(),
            'employees' => Employee::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'employeeSkills' => DB::table('production_employee_skills')->where('company_id', $this->company()->id)->get(['employee_id', 'skill_id'])->groupBy('employee_id'),
            'ownWeek' => WorkPattern::query()->distinct()->pluck('employee_id')->all(),
            'reasons' => DowntimeReason::cases(),
            'planningOn' => app(ModuleManager::class)->isEnabled(PlanningModule::KEY, $this->company()),
        ]);
    }
}
