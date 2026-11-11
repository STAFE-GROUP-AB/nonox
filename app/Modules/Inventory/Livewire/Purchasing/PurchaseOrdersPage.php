<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Models\Supplier;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Livewire\Concerns\PurchasingPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lager → Inköpsorder: the company's purchase orders, the open ones first,
 * filtered by status and supplier in the URL. A fixed number of queries per
 * page, whatever the number of orders and rows.
 */
class PurchaseOrdersPage extends Component
{
    use PurchasingPage;
    use WithPagination;

    public const string NAME = 'inventory.purchase-orders';

    #[Url(as: 'visa')]
    public string $status = 'oppna';

    #[Url(as: 'leverantor')]
    public string $supplier = '';

    public function mount(): void
    {
        abort_unless($this->canPurchase('viewAny'), 403);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSupplier(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $orders = PurchaseOrder::query()
            ->with(['supplier:id,name', 'rows:id,purchase_order_id,entered_quantity,price_ore,quantity_milli,received_milli'])
            ->when($this->status === 'oppna', fn ($query) => $query->whereIn('status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value]))
            ->when($this->status === 'utkast', fn ($query) => $query->where('status', PurchaseOrderStatus::Draft->value))
            ->when($this->status === 'vantar', fn ($query) => $query->whereIn('status', [PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value]))
            ->when(ctype_digit($this->supplier), fn ($query) => $query->where('supplier_id', (int) $this->supplier))
            ->latest('number')
            ->paginate(25);

        return view('inventory::livewire.purchasing.orders', [
            'orders' => $orders,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $this->canPurchase('manage'),
        ]);
    }
}
