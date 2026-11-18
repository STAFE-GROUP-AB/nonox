<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire\Purchasing;

use App\Actions\Approval\RejectApproval;
use App\Actions\Approval\ResubmitApproval;
use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalState;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\SubjectFacts;
use App\Modules\Inventory\Actions\Purchasing\ApprovePurchaseRequisition;
use App\Modules\Inventory\Actions\Purchasing\PurchaseRequisitionLifecycle;
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\Livewire\Concerns\InventoryPage;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One purchase requisition (ADR-081 E9): its rows, where it stands in the
 * approval, and what the viewer can do: the requester sends, takes back, changes
 * or cancels; an approver approves or rejects. Approving and rejecting name the
 * requisition and approval round this page showed (`seenSubject`), so something
 * changed or taken back after the page was opened is never decided unseen.
 */
class RequisitionPage extends Component
{
    use InventoryPage;

    public const string NAME = 'inventory.requisition';

    #[Locked]
    public int $requisitionId;

    /** Why the requisition is rejected, optional. */
    public string $rejectReason = '';

    /** The requisition and approval round this page last showed. */
    #[Locked]
    public string $seenSubject = '';

    public function mount(int $requisitionId): void
    {
        $requisition = PurchaseRequisition::query()->findOrFail($requisitionId);
        abort_unless(Gate::forUser($this->user())->allows('view', [$requisition, $this->company()]), 403);
        $this->requisitionId = $requisition->id;
    }

    public function submit(PurchaseRequisitionLifecycle $lifecycle): void
    {
        $requisition = $this->requisition();

        $this->attempt(fn () => $lifecycle->submit($this->user(), $this->company(), $requisition), __(':title är skickad till attest.', ['title' => $requisition->title()]), '');
    }

    public function takeBack(PurchaseRequisitionLifecycle $lifecycle): void
    {
        $requisition = $this->requisition();

        $this->attempt(fn () => $lifecycle->takeBack($this->user(), $this->company(), $requisition), __(':title är tillbakatagen och ett utkast igen.', ['title' => $requisition->title()]), '');
    }

    public function cancelRequisition(PurchaseRequisitionLifecycle $lifecycle): void
    {
        $requisition = $this->requisition();

        $this->attempt(fn () => $lifecycle->cancel($this->user(), $this->company(), $requisition), __(':title är makulerad.', ['title' => $requisition->title()]), '');
    }

    public function approve(ApprovePurchaseRequisition $approve): void
    {
        $requisition = $this->requisition();
        $approved = null;

        $ok = $this->attempt(function () use ($approve, &$approved): void {
            $approved = $approve($this->user(), $this->company(), $this->seenSubject);
        }, '');

        if ($ok && $approved instanceof PurchaseRequisition) {
            $this->toast($approved->status === PurchaseRequisitionStatus::Approved
                ? __(':title är attesterad och kan beställas av en inköpare.', ['title' => $approved->title()])
                : __('Ditt godkännande av :title är sparat. Anmodan väntar på nästa attest.', ['title' => $requisition->title()]));
        }
    }

    public function reject(RejectApproval $reject): void
    {
        $requisition = $this->requisition();

        $ok = $this->attempt(fn () => $reject($this->user(), $this->company(), ApprovalSubjectType::PurchaseRequisition, $this->seenSubject, $this->rejectReason), '');

        if ($ok) {
            $this->rejectReason = '';
            $this->toast(__(':title är avvisad och går tillbaka till den som skrev den.', ['title' => $requisition->title()]));
        }
    }

    public function resubmit(ResubmitApproval $resubmit): void
    {
        $requisition = $this->requisition();

        if ($this->attempt(fn () => $resubmit($this->user(), $this->company(), ApprovalSubjectType::PurchaseRequisition, $this->seenSubject), '')) {
            $this->toast(__(':title är skickad till attest igen.', ['title' => $requisition->title()]));
        }
    }

    public function render(): View
    {
        $requisition = PurchaseRequisition::query()->with([
            'requester:id,name',
            'rows.unit:id,code',
            'rows.article:id,number,name',
            'rows.account:id,number,name',
            'rows.costCenter:id,code,name',
            'rows.project:id,code,name',
            'rows.supplier:id,name',
            'rows.purchaseOrderRow:id,purchase_order_id',
        ])->findOrFail($this->requisitionId);

        $this->seenSubject = PurchaseRequisitionApprovals::subjectId($requisition);
        $user = $this->user();
        $company = $this->company();
        $gate = Gate::forUser($user);
        $custom = $requisition->status !== PurchaseRequisitionStatus::Draft && $requisition->status !== PurchaseRequisitionStatus::Cancelled && PurchaseRequisitionApprovals::hasPolicy($requisition);
        $approval = $custom ? $this->approval($requisition) : null;
        $submitted = $requisition->status === PurchaseRequisitionStatus::Submitted;
        $approves = $user->hasTeamPermission($company, ApprovalSubjectType::PurchaseRequisition->basePermission());
        $preparer = in_array($user->id, [$requisition->requested_by, $requisition->prepared_by], true);
        $mayHandle = $gate->allows('handle', [$requisition, $company]);
        $orderIds = $requisition->rows->map(fn ($row) => $row->purchaseOrderRow?->purchase_order_id)->filter()->unique()->values()->all();

        return view('inventory::livewire.purchasing.requisition', [
            'requisition' => $requisition,
            'approval' => $approval,
            'memberNames' => $approval instanceof ApprovalState ? $company->allUsers()->pluck('name', 'id')->all() : [],
            'canApprove' => $submitted && ($approval instanceof ApprovalState
                ? $approval->canDecide()
                : $approves && ! ($company->separate_supplier_approval && $preparer)),
            'canReject' => $submitted && ($approval instanceof ApprovalState
                ? ($approval->canDecide() || $user->hasTeamPermission($company, 'approvals:manage'))
                : $approves || $user->hasTeamPermission($company, 'approvals:manage')),
            'blockedBySegregation' => $submitted && $approves && ! $approval instanceof ApprovalState && $company->separate_supplier_approval && $preparer,
            'canResubmitFlow' => $approval?->awaitingResubmission === true && $mayHandle,
            'mayHandle' => $mayHandle,
            'canConvert' => $gate->allows('convert', [PurchaseRequisition::class, $company]),
            'orders' => $orderIds === [] ? collect() : PurchaseOrder::query()->whereKey($orderIds)->pluck('number', 'id'),
        ]);
    }

    /** Where the requisition stands in the company's own approval policy, or null for the default flow. */
    private function approval(PurchaseRequisition $requisition): ?ApprovalState
    {
        $engine = app(ApprovalEngine::class);
        $subjectId = PurchaseRequisitionApprovals::subjectId($requisition);
        $history = $engine->histories($requisition->company_id, ApprovalSubjectType::PurchaseRequisition, [$subjectId])->get($subjectId, collect());
        $state = $engine->state(ApprovalContext::load($this->company()), $this->user(), SubjectFacts::forPurchaseRequisition($requisition), $history);

        return $state->custom ? $state : null;
    }

    private function requisition(): PurchaseRequisition
    {
        return PurchaseRequisition::query()->findOrFail($this->requisitionId);
    }
}
