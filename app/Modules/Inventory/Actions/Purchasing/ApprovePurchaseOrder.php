<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDecisionKind;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DecisionOutcome;
use App\Domain\Approval\SubjectFacts;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseOrderApprovals;
use App\Modules\Inventory\Purchasing\PurchaseOrderReceipts;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a draft purchase order on the current step of the company's own
 * approval policy (ADR-081 E10). The order is approved, and so may be sent,
 * only with the last approval of the last step. An order that no policy
 * applies to follows the default policy (the amount threshold), which has no
 * separate approval step: whoever may send the order sends it
 * (SendPurchaseOrder). The call names the order and approval round the approver
 * saw, so something saved after they opened the page is never approved unseen.
 * Rejecting and sending back to approval go through
 * RejectApproval and ResubmitApproval.
 */
class ApprovePurchaseOrder
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, string $subjectId): PurchaseOrder
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        return CurrentCompany::run($company, fn (): PurchaseOrder => DB::transaction(function () use ($user, $company, $subjectId): PurchaseOrder {
            // Receipts first, then the order (PurchaseOrderReceipts).
            PurchaseOrderReceipts::lockOpen((int) strtok($subjectId, '.'));
            // The round the approver saw: an order saved since then is refused.
            $order = PurchaseOrderApprovals::lock($subjectId);

            if (! $order->isDraft() || $order->isApproved()) {
                throw ValidationException::withMessages(['approve' => __('Inköpsordern väntar inte på attest.')]);
            }

            $order->load('rows');
            $facts = SubjectFacts::forPurchaseOrder($order);
            $outcome = $this->engine->decide(ApprovalContext::load($company), $user, $facts, ApprovalDecisionKind::Approve);

            if ($outcome === DecisionOutcome::Default) {
                throw ValidationException::withMessages(['approve' => __('Ordern följer standardflödet och behöver ingen särskild attest. Den som får skicka ordern skickar den.')]);
            }

            if ($outcome !== DecisionOutcome::Completed) {
                return $order;
            }

            $request = ApprovalRequest::query()
                ->where('company_id', $company->id)
                ->where('subject_type', ApprovalSubjectType::PurchaseOrder->value)
                ->where('subject_id', $facts->subjectId)
                ->where('status', ApprovalRequestStatus::Approved->value)
                ->orderByDesc('id')
                ->firstOrFail();

            $order->forceFill(['approval_request_id' => $request->id, 'approved_by' => $user->id, 'approved_at' => now()])->save();

            Audit::record('inventory.purchase_order_approved', $order, ['number' => $order->number, 'version' => $order->version, 'round' => $order->approval_round, 'approval_request_id' => $request->id], $company, $user);

            return $order;
        }));
    }
}
