<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire\Planning;

use App\Modules\Manufacturing\Actions\Planning\HoldOperation;
use App\Modules\Manufacturing\Actions\Planning\PlanChanges;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Livewire\Concerns\PlanningScreen;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Manufacturing\Models\ProductionPlanLock;
use App\Modules\Manufacturing\Models\ProductionPlanSlot;
use App\Modules\Manufacturing\Planning\PlanBoard;
use App\Modules\Manufacturing\Planning\PlanningSettings;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tillverkning → Planering → Ordrar (ADR-071 §13.1): every production
 * order in the plan with customer, promised date, forecast, the difference
 * in working days, the traffic light and what it waits for. A planner opens
 * an order to lock, move or split its operations, give it a priority, or
 * enter a new estimate of the minutes an operation still needs
 * (production:plan).
 */
class OrdersPage extends Component
{
    use PlanningScreen;

    #[Url(as: 'order')]
    public ?int $openId = null;

    /** @var array<int|string, string> */
    public array $priority = [];

    /** @var array<int|string, string> */
    public array $remaining = [];

    /** @var array<int|string, string> */
    public array $assignTo = [];

    /** @var array<int|string, string> */
    public array $splitTo = [];

    /** @var array<int|string, string> */
    public array $splitMinutes = [];

    public function open(?int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $this->resetErrorBag();
    }

    public function savePriority(int $orderId, PlanChanges $changes): void
    {
        abort_unless($this->can('plan'), 403);
        $order = ProductionOrder::query()->findOrFail($orderId);
        $text = trim((string) ($this->priority[$orderId] ?? ''));

        $this->attempt(fn () => $changes->prioritise($this->user(), $this->company(), $order, $text === '' ? null : (int) $text), $text === ''
            ? __('Prioriteten för :order är borttagen.', ['order' => $order->reference()])
            : __('Prioriteten för :order är sparad. Planen beräknas om.', ['order' => $order->reference()]), 'priority.');
    }

    public function lock(int $slotId, HoldOperation $hold): void
    {
        abort_unless($this->can('plan'), 403);
        $slot = ProductionPlanSlot::query()->findOrFail($slotId);

        $this->attempt(fn () => $hold->lock($this->user(), $this->company(), $slot), __('Arbetssteget är låst där det ligger.'));
    }

    public function release(int $lockId, HoldOperation $hold): void
    {
        abort_unless($this->can('plan'), 403);
        $lock = ProductionPlanLock::query()->findOrFail($lockId);

        $this->attempt(fn () => $hold->release($this->user(), $this->company(), $lock), __('Låsningen är borttagen.'));
    }

    public function assign(int $operationId, HoldOperation $hold): void
    {
        abort_unless($this->can('plan'), 403);
        $operation = ProductionOrderOperation::query()->findOrFail($operationId);

        $this->attempt(fn () => $hold->assign($this->user(), $this->company(), $operation, (int) ($this->assignTo[$operationId] ?? 0)), __('Arbetssteget är flyttat. Planen beräknas om.'), 'assign.');
    }

    public function split(int $operationId, HoldOperation $hold): void
    {
        abort_unless($this->can('plan'), 403);
        $operation = ProductionOrderOperation::query()->findOrFail($operationId);

        $this->attempt(fn () => $hold->split($this->user(), $this->company(), $operation, (int) ($this->splitTo[$operationId] ?? 0), (int) ($this->splitMinutes[$operationId] ?? 0)), __('Arbetssteget är delat. Planen beräknas om.'), 'split.');
    }

    public function saveRemaining(int $operationId, string $type, PlanChanges $changes): void
    {
        abort_unless($this->can('plan'), 403);
        $operation = ProductionOrderOperation::query()->findOrFail($operationId);
        $text = trim((string) ($this->remaining[$operationId] ?? ''));

        $this->attempt(fn () => $changes->remaining($this->user(), $this->company(), $operation, $text === '' ? null : (int) $text, DeviationType::tryFrom($type) ?? DeviationType::Overrun), __('Återstående tid är sparad. Planen beräknas om.'), 'remaining.');
    }

    public function render(PlanBoard $board): View
    {
        $run = $this->run();
        $forecasts = $run === null ? collect() : $board->forecasts($this->company(), $run);
        $calendar = PlanningSettings::for($this->company())->calendar();
        $open = null;

        if ($this->openId !== null && $forecasts->contains('production_order_id', $this->openId)) {
            $open = ProductionOrder::query()
                ->with(['operations.operation:id,name,required_skill_id', 'operations.workstation:id,name'])
                ->findOrFail($this->openId);
        }

        $slots = $open === null || $run === null ? collect() : ProductionPlanSlot::query()->where('run_id', $run->id)->where('production_order_id', $open->id)->with('employee:id,first_name,last_name', 'workstation:id,name')->orderBy('starts_at')->get()->groupBy('production_order_operation_id');
        $locks = $open === null ? collect() : ProductionPlanLock::query()->whereIn('production_order_operation_id', $open->operations->pluck('id'))->with('employee:id,first_name,last_name')->get()->groupBy('production_order_operation_id');
        $skills = $open === null ? collect() : DB::table('production_employee_skills')->where('company_id', $this->company()->id)->get(['employee_id', 'skill_id'])->groupBy('skill_id');
        $people = $open === null ? collect() : Employee::query()->whereIn('id', DB::table('production_employee_skills')->where('company_id', $this->company()->id)->select('employee_id'))->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return view('manufacturing::livewire.planning.orders', [
            'run' => $run,
            'canPlan' => $this->can('plan'),
            'forecasts' => $forecasts,
            'calendar' => $calendar,
            'open' => $open,
            'bars' => $slots,
            'locks' => $locks,
            'qualified' => fn (?int $skill) => $skill === null ? $people : $people->whereIn('id', $skills->get($skill, collect())->pluck('employee_id')->all()),
        ]);
    }
}
