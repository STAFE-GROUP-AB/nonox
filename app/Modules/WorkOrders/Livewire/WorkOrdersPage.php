<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Livewire;

use App\Modules\WorkOrders\Domain\WorkOrderStatus;
use App\Modules\WorkOrders\Livewire\Concerns\WorkOrdersPage as Page;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\Policies\WorkOrderPolicy;
use App\Modules\WorkOrders\Support\Uninvoiced;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Arbetsorder → Alla arbetsorder: search, status filter in the URL, and per
 * work order the uninvoiced construction work whose invoice deadline is
 * near (ML 17 kap. 16 §, AO-R22). A fixed number of queries per page.
 */
class WorkOrdersPage extends Component
{
    use Page;
    use WithPagination;

    #[Url(as: 'sok')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = 'oppna';

    public function mount(): void
    {
        abort_unless($this->can('viewAny'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim($this->search);
        $onlyAssigned = WorkOrderPolicy::onlyAssigned($this->user(), $this->company());

        $workOrders = WorkOrder::query()
            ->with('customer:id,name')
            ->when($onlyAssigned, fn ($query) => $query->whereIn('id', WorkOrderAssignment::query()->select('work_order_id')->where('user_id', $this->user()->id)))
            ->when($this->status === 'oppna', fn ($query) => $query->whereNotIn('status', [WorkOrderStatus::Closed->value, WorkOrderStatus::Cancelled->value]))
            ->when(WorkOrderStatus::tryFrom($this->status) !== null, fn ($query) => $query->where('status', $this->status))
            ->when($term !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('title', 'ilike', "%{$term}%")
                ->orWhere('number', ctype_digit($term) ? (int) $term : -1)
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'ilike', "%{$term}%"))))
            ->orderByDesc('number')
            ->paginate(25);

        $ids = $workOrders->getCollection()->pluck('id')->all();
        $earliest = [];

        $rows = [
            ...Uninvoiced::open(TimeEntry::query()->whereIn('work_order_id', $ids))->groupBy('work_order_id')->selectRaw('work_order_id, MIN(work_date) AS earliest')->toBase()->get()->all(),
            ...Uninvoiced::open(MaterialEntry::query()->whereIn('work_order_id', $ids))->groupBy('work_order_id')->selectRaw('work_order_id, MIN(work_date) AS earliest')->toBase()->get()->all(),
        ];

        foreach ($rows as $row) {
            $earliest[(int) $row->work_order_id] = min($earliest[(int) $row->work_order_id] ?? '9999-12-31', (string) $row->earliest);
        }

        $today = CarbonImmutable::today();
        $deadlines = [];

        foreach ($workOrders as $workOrder) {
            if ($workOrder->is_construction && isset($earliest[$workOrder->id])) {
                $deadline = CarbonImmutable::parse($earliest[$workOrder->id])->startOfMonth()->addMonthsNoOverflow(2)->endOfMonth();
                $deadlines[$workOrder->id] = $today->greaterThanOrEqualTo($deadline->startOfMonth()) ? $deadline : null;
            }
        }

        return view('work-orders::livewire.index', [
            'workOrders' => $workOrders,
            'deadlines' => $deadlines,
            'unbilled' => $earliest,
            'canManage' => $this->can('manage'),
            'statuses' => WorkOrderStatus::cases(),
        ]);
    }
}
