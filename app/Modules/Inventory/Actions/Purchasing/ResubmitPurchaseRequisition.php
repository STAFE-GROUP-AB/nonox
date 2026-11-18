<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Purchasing;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sends a rejected purchase requisition to approval again, unchanged (ADR-081
 * E9). Whoever wrote it, or a buyer, does it. A request that was rejected in a
 * company's own flow gets a new request with the policy as it is now; the
 * rejected one stays in the history. Changing the requisition instead starts a
 * new round (SavePurchaseRequisition).
 */
class ResubmitPurchaseRequisition
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, string $subjectId): PurchaseRequisition
    {
        app(ModuleManager::class)->ensureEnabled(InventoryModule::KEY, $company);

        return CurrentCompany::run($company, fn (): PurchaseRequisition => DB::transaction(function () use ($user, $company, $subjectId): PurchaseRequisition {
            $requisition = PurchaseRequisitionApprovals::lock($subjectId);

            if (! Gate::forUser($user)->allows('handle', [$requisition, $company])) {
                throw new AuthorizationException;
            }

            if ($requisition->status !== PurchaseRequisitionStatus::Rejected) {
                throw ValidationException::withMessages(['approve' => __('Bara en avvisad inköpsanmodan kan skickas till attest igen.')]);
            }

            $requisition->load('rows');

            if (PurchaseRequisitionApprovals::hasRejectedRequest($requisition)) {
                $this->engine->resubmit(ApprovalContext::load($company), $user, SubjectFacts::forPurchaseRequisition($requisition));
            }

            $requisition->forceFill([
                'status' => PurchaseRequisitionStatus::Submitted,
                'submitted_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'reject_reason' => null,
            ])->save();

            Audit::record('inventory.purchase_requisition_resubmitted', $requisition, ['number' => $requisition->number, 'round' => $requisition->approval_round], $company, $user);

            return $requisition;
        }));
    }
}
