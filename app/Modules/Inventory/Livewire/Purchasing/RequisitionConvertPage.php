<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Models\Supplier;
use App\Modules\Inventory\Actions\Purchasing\ConvertPurchaseRequisitions;
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Models\PurchaseRequisitionRow;
use App\Support\Toast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Lager → Inköpsanmodan → Beställ: a buyer picks a supplier and the approved
 * requisition rows that go to that supplier, and they become the rows of one
 * draft purchase order (ADR-081 E9). Rows that name another supplier are not
 * offered; for what is not a stock article the buyer fills in the account and
 * price the requester left out. A fixed number of queries whatever the number of
 * rows.
 */
class RequisitionConvertPage extends Component
{
    use InventoryPage;

    public const string NAME = 'inventory.requisition-convert';

    public string $supplier = '';

    /** @var array<int, int|string> */
    public array $selected = [];

    /** @var array<int, array{account?: string, price?: string}> */
    public array $details = [];

    public function mount(): void
    {
        abort_unless(Gate::forUser($this->user())->allows('convert', [PurchaseRequisition::class, $this->company()]), 403);
    }

    public function updatedSupplier(): void
    {
        $this->selected = [];
        $this->resetErrorBag();
    }

    public function selectAll(): void
    {
        $this->selected = $this->rows()->pluck('id')->map(fn (int $id): string => (string) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function convert(ConvertPurchaseRequisitions $convert): void
    {
        $order = null;

        $ok = $this->attempt(function () use ($convert, &$order): void {
            $order = $convert($this->user(), $this->company(), array_map('intval', $this->selected), (int) $this->supplier, $this->details);
        }, '', '');

        if ($ok && $order instanceof PurchaseOrder) {
            Toast::flash(__('Inköpsorder :number är skapad som utkast av raderna. Kontrollera den och skicka den till leverantören.', ['number' => $order->number]));
            $this->redirectRoute('inventory.purchaseOrders.show', $order->id);
        }
    }

    public function render(): View
    {
        return view('inventory::livewire.purchasing.requisition-convert', [
            'rows' => $this->rows(),
            'suppliers' => Supplier::query()->whereNull('processing_restricted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The approved, not yet ordered rows: those that name the chosen supplier or
     * none, or all of them while no supplier is chosen.
     *
     * @return Collection<int, PurchaseRequisitionRow>
     */
    private function rows(): Collection
    {
        return PurchaseRequisitionRow::query()
            ->with(['requisition:id,number,needed_by,requested_by', 'requisition.requester:id,name', 'article:id,number,name,is_stock_item', 'unit:id,code', 'account:id,number', 'costCenter:id,code', 'project:id,code', 'supplier:id,name'])
            ->whereNull('purchase_order_row_id')
            ->whereHas('requisition', fn ($query) => $query->where('status', PurchaseRequisitionStatus::Approved->value))
            ->when(ctype_digit($this->supplier), fn ($query) => $query->where(fn ($inner) => $inner->whereNull('supplier_id')->orWhere('supplier_id', (int) $this->supplier)))
            ->orderBy('purchase_requisition_id')
            ->orderBy('position')
            ->get();
    }
}
