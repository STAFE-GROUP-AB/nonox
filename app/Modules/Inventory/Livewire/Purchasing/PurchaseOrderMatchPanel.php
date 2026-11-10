<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Models\SupplierInvoice;
use App\Modules\Inventory\Actions\Purchasing\MatchSupplierInvoice;
use App\Modules\Inventory\Livewire\Concerns\PurchasingPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseOrderMatch;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * On a supplier invoice's page, while Lager is on: the purchase orders the
 * invoice is matched with, their goods receipts, and the differences in
 * quantity and price (ADR-071 §2). A person matches and judges; nothing is
 * approved or booked here.
 */
class PurchaseOrderMatchPanel extends Component
{
    use PurchasingPage;

    public const string NAME = 'inventory.purchase-order-match';

    #[Locked]
    public string $invoiceId;

    public string $orderId = '';

    public function mount(string $invoiceId): void
    {
        abort_unless($this->canPurchase('viewAny'), 403);
        $this->invoiceId = SupplierInvoice::query()->findOrFail($invoiceId)->id;
    }

    public function link(MatchSupplierInvoice $match): void
    {
        if ($this->orderId === '') {
            $this->toast(__('Välj en inköpsorder.'), 'error');

            return;
        }

        $order = PurchaseOrder::query()->findOrFail((int) $this->orderId);

        if ($this->attempt(fn () => $match->link($this->user(), $this->company(), $order, $this->invoice()), __(':order är kopplad till fakturan.', ['order' => $order->title()]), '')) {
            $this->orderId = '';
        }
    }

    public function unlink(int $orderId, MatchSupplierInvoice $match): void
    {
        $order = PurchaseOrder::query()->findOrFail($orderId);
        $this->attempt(fn () => $match->unlink($this->user(), $this->company(), $order, $this->invoice()), __('Kopplingen till :order är borttagen.', ['order' => $order->title()]), '');
    }

    public function render(): View
    {
        return view('inventory::livewire.purchasing.match', [
            'match' => PurchaseOrderMatch::forInvoice($this->invoice()),
            'canManage' => $this->canPurchase('manage'),
        ]);
    }

    private function invoice(): SupplierInvoice
    {
        return SupplierInvoice::query()->findOrFail($this->invoiceId);
    }
}
