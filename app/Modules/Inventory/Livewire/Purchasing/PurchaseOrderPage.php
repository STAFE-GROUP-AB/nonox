<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalState;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\SubjectFacts;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseOrder;
use App\Modules\Inventory\Actions\Purchasing\ConfirmOrderRowDelivery;
use App\Modules\Inventory\Actions\Purchasing\PurchaseOrderLifecycle;
use App\Modules\Inventory\Actions\Purchasing\SendPurchaseOrder;
use App\Modules\Inventory\Actions\SaveReceipt;
use App\Modules\Inventory\Domain\PurchaseOrderApprovalMode;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Livewire\Concerns\PurchasingPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Policies\PurchaseOrderPolicy;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
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

    /** Why the order is rejected in the company's own approval policy (ADR-081 E10), optional. */
    public string $rejectReason = '';

    /** The order and approval round this page last showed; approving or rejecting refers to it. */
    #[Locked]
    public string $seenSubject = '';

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

    public function approve(ApprovePurchaseOrder $approve): void
    {
        $order = $this->order();
        $approved = null;

        $ok = $this->attempt(function () use ($approve, &$approved): void {
            $approved = $approve($this->user(), $this->company(), $this->seenSubject);
        }, '');

        if ($ok && $approved instanceof PurchaseOrder) {
            $this->toast($approved->isApproved()
                ? __(':title är attesterad och kan skickas till leverantören.', ['title' => $approved->title()])
                : __('Ditt godkännande av :title är sparat. Ordern väntar på nästa attest.', ['title' => $approved->title()]));
        }
    }

    public function reject(RejectApproval $reject): void
    {
        $order = $this->order();

        $ok = $this->attempt(fn () => $reject($this->user(), $this->company(), ApprovalSubjectType::PurchaseOrder, $this->seenSubject, $this->rejectReason), '');

        if ($ok) {
            $this->rejectReason = '';
            $this->toast(__(':title är avvisad och går tillbaka till den som skapade den.', ['title' => $order->title()]));
        }
    }

    public function resubmit(ResubmitApproval $resubmit): void
    {
        $order = $this->order();

        if ($this->attempt(fn () => $resubmit($this->user(), $this->company(), ApprovalSubjectType::PurchaseOrder, $this->seenSubject), '')) {
            $this->toast(__(':title är skickad till attest igen.', ['title' => $order->title()]));
        }
    }

    public function confirmDelivery(ConfirmOrderRowDelivery $confirm, int $rowId, bool $confirmed = true): void
    {
        $this->attempt(fn () => $confirm($this->user(), $this->company(), $this->order(), $rowId, $confirmed), $confirmed ? __('Leveransen är bekräftad. Inget har lagts in i lagret.') : __('Bekräftelsen är borttagen.'), '');
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
            'approver:id,name',
            'versions.approver:id,name',
            'rows.unit:id,code',
            'rows.account:id,number,name',
            'rows.costCenter:id,code,name',
            'rows.project:id,code,name',
            'rows.article:id,number,name,unit,stock_unit_id',
            'rows.article.stockUnit:id,code',
            'receipts:id,purchase_order_id,number,status,created_at',
            'supplierInvoices',
        ])->findOrFail($this->orderId);

        $this->seenSubject = PurchaseOrderApprovals::subjectId($order);
        $mode = PurchaseOrderApprovals::mode($order, $this->company());
        $approval = $this->approval($order, $mode);

        return view('inventory::livewire.purchasing.show', [
            'order' => $order,
            'mode' => $mode,
            'approval' => $approval,
            'memberNames' => $approval instanceof ApprovalState ? $this->company()->allUsers()->pluck('name', 'id')->all() : [],
            'canApprove' => $approval instanceof ApprovalState && $order->isDraft() && ! $order->isApproved() && ! $approval->awaitingResubmission && $approval->canDecide(),
            'canReject' => $approval instanceof ApprovalState && $order->isDraft() && ! $order->isApproved() && ! $approval->awaitingResubmission
                && ($approval->canDecide() || $this->user()->hasTeamPermission($this->company(), 'approvals:manage')),
            'canResubmit' => $approval?->awaitingResubmission === true && $order->isDraft()
                && ($order->created_by === $this->user()->id || $this->user()->hasTeamPermission($this->company(), ApprovalSubjects::preparePermission(ApprovalSubjectType::PurchaseOrder))),
            'hasStockRows' => $order->rows->contains(fn ($row): bool => $row->isStock()),
            'canManage' => $this->canPurchase('manage'),
            'canSend' => $this->canSend($order) && ($mode !== PurchaseOrderApprovalMode::Policy || $order->isApproved()),
            'canReceive' => $this->can('count'),
            'needsApproval' => PurchaseOrderPolicy::needsApproval($order, $this->company()),
            'approvalLimit' => InventorySettings::purchaseApprovalAboveOre($this->company()),
        ]);
    }

    /** Where the order stands in the company's own approval policy, or null for the default policy (the amount threshold). */
    private function approval(PurchaseOrder $order, PurchaseOrderApprovalMode $mode): ?ApprovalState
    {
        if ($mode !== PurchaseOrderApprovalMode::Policy || ! $order->isDraft()) {
            return null;
        }

        $engine = app(ApprovalEngine::class);
        $subjectId = PurchaseOrderApprovals::subjectId($order);
        $history = $engine->histories($order->company_id, ApprovalSubjectType::PurchaseOrder, [$subjectId])->get($subjectId, collect());
        $state = $engine->state(ApprovalContext::load($this->company()), $this->user(), SubjectFacts::forPurchaseOrder($order), $history);

        return $state->custom ? $state : null;
    }

    private function order(): PurchaseOrder
    {
        return PurchaseOrder::query()->with('supplier')->findOrFail($this->orderId);
    }
}
