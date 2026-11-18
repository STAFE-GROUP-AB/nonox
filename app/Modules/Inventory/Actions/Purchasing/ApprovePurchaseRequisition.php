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
use App\Modules\Inventory\Domain\PurchaseRequisitionStatus;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Modules\Inventory\Purchasing\PurchaseRequisitionApprovals;
use App\Modules\ModuleManager;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a purchase requisition that waits for approval (ADR-081 E9). The
 * call names the requisition and approval round the approver saw (the subject
 * id), so something changed or taken back after they opened the page is never
 * approved unseen.
 *
 * With a policy of the company's own, the call records the approval on the
 * current step, and the requisition is approved with the last approval of the
 * last step. With none, one person who may send purchase orders approves it
 * (the default flow), never the person who wrote or last changed it while the
 * company requires separate approval (AGENTS.md §4).
 */
class ApprovePurchaseRequisition
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, string $subjectId): PurchaseRequisition
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        return CurrentCompany::run($company, fn (): PurchaseRequisition => DB::transaction(function () use ($user, $company, $subjectId): PurchaseRequisition {
            $requisition = PurchaseRequisitionApprovals::lock($subjectId);

            if ($requisition->status !== PurchaseRequisitionStatus::Submitted) {
                throw ValidationException::withMessages(['approve' => __('Inköpsanmodan väntar inte på attest.')]);
            }

            $requisition->load('rows');
            $facts = SubjectFacts::forPurchaseRequisition($requisition);
            $outcome = $this->engine->decide(ApprovalContext::load($company), $user, $facts, ApprovalDecisionKind::Approve);

            if ($outcome === DecisionOutcome::Default) {
                $this->authorizeDefault($user, $company, $requisition);

                return $this->apply($user, $requisition, null);
            }

            if ($outcome !== DecisionOutcome::Completed) {
                return $requisition;
            }

            $request = ApprovalRequest::query()
                ->where('company_id', $company->id)
                ->where('subject_type', ApprovalSubjectType::PurchaseRequisition->value)
                ->where('subject_id', $facts->subjectId)
                ->where('status', ApprovalRequestStatus::Approved->value)
                ->orderByDesc('id')
                ->firstOrFail();

            return $this->apply($user, $requisition, $request->id);
        }));
    }

    /** The default flow: purchase-orders:send, and not the preparer while the company requires separate approval. */
    private function authorizeDefault(User $user, Company $company, PurchaseRequisition $requisition): void
    {
        if (! $user->hasTeamPermission($company, ApprovalSubjectType::PurchaseRequisition->basePermission())) {
            throw new AuthorizationException(__('Du har inte rätt att attestera inköpsanmodan.'));
        }

        if ($company->separate_supplier_approval && in_array($user->id, [$requisition->requested_by, $requisition->prepared_by], true)) {
            throw ValidationException::withMessages(['approve' => __('Du skrev eller ändrade inköpsanmodan och kan därför inte attestera den. Någon annan med rätt att attestera behöver göra det.')]);
        }
    }

    private function apply(User $user, PurchaseRequisition $requisition, ?int $requestId): PurchaseRequisition
    {
        $requisition->forceFill([
            'status' => PurchaseRequisitionStatus::Approved,
            'approval_request_id' => $requestId,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'reject_reason' => null,
        ])->save();

        Audit::record('inventory.purchase_requisition_approved', $requisition, ['number' => $requisition->number, 'round' => $requisition->approval_round, 'approval_request_id' => $requestId], $requisition->company_id, $user);

        return $requisition;
    }
}
