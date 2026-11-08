<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire;

use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Livewire\Concerns\WorkOrdersPage;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\WorkOrdersModule;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Mina arbetsorder (ADR-074 §8): the planned and ongoing work orders the
 * user is assigned to, with the hours they reported today. Cards, no
 * tables; two queries whatever the number of jobs.
 */
class MyWorkOrdersPage extends Component
{
    use WorkOrdersPage;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), WorkOrdersModule::REPORT), 403);
    }

    public function render(): View
    {
        $workOrders = WorkOrder::query()
            ->whereIn('id', WorkOrderAssignment::query()->select('work_order_id')->where('user_id', $this->user()->id))
            ->whereIn('status', [WorkOrderStatus::Planned->value, WorkOrderStatus::InProgress->value])
            ->with('customer:id,name')
            ->orderBy('planned_start')
            ->orderBy('number')
            ->get();

        $today = TimeEntry::query()->whereIn('work_order_id', $workOrders->pluck('id'))->where('user_id', $this->user()->id)
            ->whereDate('work_date', CarbonImmutable::today()->toDateString())->whereNotNull('minutes')
            ->groupBy('work_order_id')->selectRaw('work_order_id, SUM(minutes) AS minutes')->toBase()->pluck('minutes', 'work_order_id');

        return view('work-orders::livewire.mine', ['workOrders' => $workOrders, 'today' => $today]);
    }
}
