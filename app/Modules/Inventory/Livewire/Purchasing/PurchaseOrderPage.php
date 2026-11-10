<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderLifecycle;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Livewire\Concerns\PurchasingPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Policies\PurchaseOrderPolicy;
use App\Support\Toast;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One purchase order: its rows in their own unit with the stock-unit
 * equivalent and what has arrived (ADR-071 §12.5), the sent versions, the
 * goods receipts and the matched supplier invoices. Sending shows the PDF
 * and the recipient first; nothing goes until the user presses send (§2).
 */
class PurchaseOrderPage extends Component
{
    use PurchasingPage;

    public const string NAME = 'inventory.purchase-order';

    #[Locked]
    public int $orderId;

    public bool $sending = false;

    public string $email = '';

    public function mount(int $orderId): void
    {
        abort_unless($this->canPurchase('viewAny'), 403);
        $this->orderId = PurchaseOrder::query()->findOrFail($orderId)->id;
    }

    public function openSend(): void
    {
        $order = $this->order();
        abort_unless($this->canSend($order), 403);

        $this->sending = true;
        $this->email = (string) ($order->sent_to ?? $order->supplier?->email);
        $this->resetErrorBag();
    }

    public function closeSend(): void
    {
        $this->reset('sending', 'email');
        $this->resetErrorBag();
    }

    public function sendEmail(SendPurchaseOrder $send): void
    {
        if ($this->attempt(fn () => $send->email($this->user(), $this->company(), $this->order(), trim($this->email)), __('Inköpsordern är skickad till :email.', ['email' => trim($this->email)]), '')) {
            $this->closeSend();
        }
    }

    public function markPrinted(SendPurchaseOrder $send): void
    {
        if ($this->attempt(fn () => $send->printed($this->user(), $this->company(), $this->order()), __('Inköpsordern är markerad som skickad. Den väntar nu på varor.'), '')) {
            $this->closeSend();
        }
    }

    public function revise(PurchaseOrderLifecycle $lifecycle): void
    {
        $revised = null;
        $ok = $this->attempt(function () use ($lifecycle, &$revised): void {
            $revised = $lifecycle->revise($this->user(), $this->company(), $this->order());
        }, '');

        if ($ok && $revised instanceof PurchaseOrder) {
            Toast::flash(__('Version :version är ett utkast. Ändra raderna och skicka ordern igen.', ['version' => $revised->version]));
            $this->redirectRoute('inventory.purchaseOrders.edit', $revised->id);
        }
    }

    public function cancelOrder(PurchaseOrderLifecycle $lifecycle): void
    {
        $this->attempt(fn () => $lifecycle->cancel($this->user(), $this->company(), $this->order()), __('Inköpsordern är makulerad. Meddela leverantören om den redan var skickad.'), '');
    }

    /** Opens the goods receipt for the order on Inleveranser, made now if there is none. */
    public function receive(SaveReceipt $save): void
    {
        $receipt = null;
        $ok = $this->attempt(function () use ($save, &$receipt): void {
            $receipt = $save->fromPurchaseOrder($this->user(), $this->company(), $this->order());
        }, '');

        if ($ok && $receipt instanceof StockReceipt) {
            $this->redirectRoute('inventory.receipts', ['inleverans' => $receipt->id]);
        }
    }

    public function render(): View
    {
        $order = PurchaseOrder::query()->with([
            'supplier',
            'creator:id,name',
            'rows.unit:id,code',
            'rows.article:id,number,name,unit,stock_unit_id',
            'rows.article.stockUnit:id,code',
            'versions',
            'receipts:id,purchase_order_id,number,status,created_at',
            'supplierInvoices',
        ])->findOrFail($this->orderId);

        return view('inventory::livewire.purchasing.show', [
            'order' => $order,
            'canManage' => $this->canPurchase('manage'),
            'canSend' => $this->canSend($order),
            'canReceive' => $this->can('count'),
            'needsApproval' => PurchaseOrderPolicy::needsApproval($order, $this->company()),
            'approvalLimit' => InventorySettings::purchaseApprovalAboveOre($this->company()),
        ]);
    }

    private function order(): PurchaseOrder
    {
        return PurchaseOrder::query()->with('supplier')->findOrFail($this->orderId);
    }
}
