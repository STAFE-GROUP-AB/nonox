<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDecisionKind;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\SubjectFacts;
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
 * Rejects a purchase requisition that waits for approval, with an optional
 * reason (ADR-081 E9). It goes back to the requester, who can change it or send
 * it again. The call names the requisition and round the decider saw.
 *
 * With a policy of the company's own the rejection is a decision on the current
 * step (and owners and administrators may always reject). With none, anyone
 * who may approve it under the default flow may reject it. Unlike a purchase
 * order, a requisition's own status says it was rejected, so the requester
 * sees it in their list in both flows.
 */
class RejectPurchaseRequisition
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, string $subjectId, ?string $reason = null): PurchaseRequisition
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        return CurrentCompany::run($company, fn (): PurchaseRequisition => DB::transaction(function () use ($user, $company, $subjectId, $reason): PurchaseRequisition {
            $requisition = PurchaseRequisitionApprovals::lock($subjectId);

            if ($requisition->status !== PurchaseRequisitionStatus::Submitted) {
                throw ValidationException::withMessages(['approve' => __('Inköpsanmodan väntar inte på attest.')]);
            }

            $requisition->load('rows');
            $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

            if ($reason !== null && mb_strlen($reason) > ApprovalEngine::REASON_MAX) {
                throw ValidationException::withMessages(['reason' => __('Skälet får vara högst :max tecken.', ['max' => ApprovalEngine::REASON_MAX])]);
            }

            if (PurchaseRequisitionApprovals::hasPolicy($requisition)) {
                $this->engine->decide(ApprovalContext::load($company), $user, SubjectFacts::forPurchaseRequisition($requisition), ApprovalDecisionKind::Reject, $reason);
            } elseif (! $user->hasTeamPermission($company, ApprovalSubjectType::PurchaseRequisition->basePermission()) && ! $user->hasTeamPermission($company, 'approvals:manage')) {
                throw new AuthorizationException(__('Du har inte rätt att avvisa inköpsanmodan.'));
            }

            $requisition->forceFill([
                'status' => PurchaseRequisitionStatus::Rejected,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'reject_reason' => $reason,
                'approval_request_id' => null,
            ])->save();

            Audit::record('inventory.purchase_requisition_rejected', $requisition, ['number' => $requisition->number, 'round' => $requisition->approval_round], $company, $user);

            return $requisition;
        }));
    }
}
