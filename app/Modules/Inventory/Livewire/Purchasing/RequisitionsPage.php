<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Inköpsanmodan: the requisitions the user may see, the open ones
 * first, filtered by status in the URL (ADR-081 E9). Someone who only writes
 * requisitions sees their own; someone with purchase-requisitions:view sees
 * everyone's. A fixed number of queries per page, whatever the number of
 * requisitions and rows.
 */
class RequisitionsPage extends Component
{
    use InventoryPage;
    use WithPagination;

    public const string NAME = 'inventory.requisitions';

    #[Url(as: 'visa')]
    public string $status = 'oppna';

    #[Url(as: 'mina')]
    public bool $mine = false;

    public function mount(): void
    {
        abort_unless(Gate::forUser($this->user())->allows('viewAny', [PurchaseRequisition::class, $this->company()]), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedMine(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $company = $this->company();
        $gate = Gate::forUser($user);
        $seesAll = $gate->allows('viewAll', [PurchaseRequisition::class, $company]);

        $requisitions = PurchaseRequisition::query()
            ->with(['requester:id,name', 'rows:id,purchase_requisition_id,quantity_milli,estimated_price_ore,purchase_order_row_id'])
            ->when(! $seesAll || $this->mine, fn ($query) => $query->where('requested_by', $user->id))
            ->when($this->status === 'oppna', fn ($query) => $query->whereIn('status', array_map(fn (PurchaseRequisitionStatus $status): string => $status->value, array_filter(PurchaseRequisitionStatus::cases(), fn (PurchaseRequisitionStatus $status): bool => $status->isOpen()))))
            ->when($this->status === 'vantar', fn ($query) => $query->where('status', PurchaseRequisitionStatus::Submitted->value))
            ->when($this->status === 'attesterade', fn ($query) => $query->where('status', PurchaseRequisitionStatus::Approved->value))
            ->latest('number')
            ->paginate(25);

        $canConvert = $gate->allows('convert', [PurchaseRequisition::class, $company]);

        return view('inventory::livewire.purchasing.requisitions', [
            'requisitions' => $requisitions,
            'seesAll' => $seesAll,
            'canCreate' => $gate->allows('create', [PurchaseRequisition::class, $company]),
            'canConvert' => $canConvert,
            'toOrder' => $canConvert ? PurchaseRequisitionRow::query()->whereNull('purchase_order_row_id')->whereHas('requisition', fn ($query) => $query->where('status', PurchaseRequisitionStatus::Approved->value))->count() : 0,
        ]);
    }
}
