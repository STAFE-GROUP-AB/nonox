<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Planning;

use App\Modules\Manufacturing\Actions\Planning\Scenarios;
use App\Modules\Manufacturing\Livewire\Concerns\PlanningScreen;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\ProductionScenario;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tillverkning → Planering → Tänk om (ADR-071 §13.6): a planner collects
 * changes (overtime, move, split, priority), computes them in memory
 * against the plan without them, and applies them or lets them expire
 * after seven days (production:plan). Nothing touches the live plan before
 * Tillämpa.
 */
class ScenarioPage extends Component
{
    use PlanningScreen;

    /** @var list<array<string, mixed>> */
    public array $changes = [];

    /** @var array<string, string> */
    public array $form = ['type' => 'OVERTIME', 'employee_id' => '', 'date' => '', 'from' => '16:00', 'to' => '19:00', 'order_operation_id' => '', 'minutes' => '', 'production_order_id' => '', 'priority' => '0'];

    #[Locked]
    public ?int $scenarioId = null;

    /** @var array<int, array{before: ?string, after: ?string, change: ?int, status: string}>|null */
    public ?array $outcome = null;

    public function mount(): void
    {
        abort_unless($this->can('plan'), 403);
    }

    public function add(Scenarios $scenarios): void
    {
        abort_unless($this->can('plan'), 403);
        $form = $this->form;
        $change = match ($form['type']) {
            'OVERTIME' => ['type' => 'OVERTIME', 'employee_id' => (int) $form['employee_id'], 'starts_at' => "{$form['date']} {$form['from']}", 'ends_at' => "{$form['date']} {$form['to']}"],
            'ASSIGN' => ['type' => 'ASSIGN', 'order_operation_id' => (int) $form['order_operation_id'], 'employee_id' => (int) $form['employee_id']],
            'SPLIT' => ['type' => 'SPLIT', 'order_operation_id' => (int) $form['order_operation_id'], 'employee_id' => (int) $form['employee_id'], 'minutes' => (int) $form['minutes']],
            default => ['type' => 'PRIORITY', 'production_order_id' => (int) $form['production_order_id'], 'priority' => $form['priority'] === '' ? null : (int) $form['priority']],
        };

        $this->attempt(function () use ($scenarios, $change): void {
            if ($change['type'] === 'OVERTIME' && ($this->form['date'] === '' || $this->form['employee_id'] === '')) {
                throw ValidationException::withMessages(['date' => __('Välj person och datum för övertiden.')]);
            }

            $this->changes = array_merge($this->changes, $scenarios->validate($this->company(), [$change]));
        }, __('Ändringen är tillagd i scenariot.'));

        $this->outcome = null;
        $this->scenarioId = null;
    }

    public function remove(int $index): void
    {
        $changes = $this->changes;
        unset($changes[$index]);
        $this->changes = array_values($changes);
        $this->outcome = null;
        $this->scenarioId = null;
    }

    public function compute(Scenarios $scenarios): void
    {
        abort_unless($this->can('plan'), 403);

        $this->attempt(function () use ($scenarios): void {
            $outcome = $scenarios->outcome($this->user(), $this->company(), $this->changes);
            $this->scenarioId = $scenarios->save($this->user(), $this->company(), $this->changes)->id;
            $this->outcome = array_map(fn (array $order): array => [...$order, 'status' => $order['status']->value], $outcome->orders);
        }, __('Scenariot är beräknat. Planen är orörd.'));
    }

    public function apply(Scenarios $scenarios): void
    {
        abort_unless($this->can('plan'), 403);
        $scenario = ProductionScenario::query()->findOrFail((int) $this->scenarioId);

        $ok = $this->attempt(fn () => $scenarios->apply($this->user(), $this->company(), $scenario), __('Scenariot är tillämpat. Planen beräknas om.'));

        if ($ok) {
            $this->changes = [];
            $this->outcome = null;
            $this->scenarioId = null;
        }
    }

    public function render(): View
    {
        $people = Employee::query()->whereIn('id', DB::table('production_employee_skills')->where('company_id', $this->company()->id)->select('employee_id'))->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        $orders = ProductionOrder::query()->whereIn('status', ['DRAFT', 'RELEASED', 'STARTED'])->orderBy('number')->get(['id', 'number', 'name', 'plan_priority']);
        $operations = ProductionOrderOperation::query()->whereIn('production_order_id', $orders->pluck('id'))->with('operation:id,name')->orderBy('production_order_id')->orderBy('position')->get(['id', 'production_order_id', 'operation_id', 'position']);

        return view('manufacturing::livewire.planning.scenario', [
            'run' => $this->run(),
            'canPlan' => true,
            'people' => $people,
            'orders' => $orders->keyBy('id'),
            'operations' => $operations,
            'outcomeRows' => $this->outcome,
        ]);
    }
}
