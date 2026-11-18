<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalRequestStatus;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\SubjectFacts;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Modules\Inventory\Models\PurchaseRequisition;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Purchase requisitions and the approval engine (ADR-081 E9), built the way
 * purchase orders are (PurchaseOrderApprovals, E10).
 *
 * The engine's subject is the requisition and its approval round ("12.3"). The
 * round is raised whenever the requisition is saved again or taken back, so an
 * approval or a rejection is always bound to what the decider saw: the page
 * carries the subject id it showed, and the action refuses it when the round has
 * moved on. Every round has its own request and decisions.
 *
 * With no approval policy of the company's own, a requisition follows the
 * default flow: one approval by someone who may send purchase orders
 * (purchase-orders:send), never the person who wrote or last changed it while
 * the company requires separate approval. That path writes no request rows.
 *
 * Runs inside the company.
 */
final class PurchaseRequisitionApprovals
{
    public static function subjectId(PurchaseRequisition $requisition): string
    {
        return $requisition->id.'.'.$requisition->approval_round;
    }

    /**
     * Locks the requisition a subject id names, in the current company (another
     * company's is not found), and checks it is still on that round.
     */
    public static function lock(string $subjectId): PurchaseRequisition
    {
        $parts = explode('.', $subjectId);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            throw new ModelNotFoundException;
        }

        $requisition = PurchaseRequisition::query()->whereKey((int) $parts[0])->lockForUpdate()->firstOrFail();

        if ($requisition->approval_round !== (int) $parts[1]) {
            throw ValidationException::withMessages(['approve' => __('Inköpsanmodan har ändrats sedan du öppnade sidan. Ladda om den och försök igen.')]);
        }

        return $requisition;
    }

    /** Whether a policy of the company's own applies, or the round already has a request. */
    public static function hasPolicy(PurchaseRequisition $requisition): bool
    {
        $requisition->loadMissing('rows');

        return app(ApprovalEngine::class)->hasCustomFlow($requisition->company_id, ApprovalSubjectType::PurchaseRequisition, self::subjectId($requisition), fn (): SubjectFacts => SubjectFacts::forPurchaseRequisition($requisition));
    }

    /** Whether the latest request of the round (not counting withdrawn ones) was rejected. */
    public static function hasRejectedRequest(PurchaseRequisition $requisition): bool
    {
        $latest = ApprovalRequest::query()
            ->where('company_id', $requisition->company_id)
            ->where('subject_type', ApprovalSubjectType::PurchaseRequisition->value)
            ->where('subject_id', self::subjectId($requisition))
            ->where('status', '!=', ApprovalRequestStatus::Withdrawn->value)
            ->orderByDesc('id')
            ->first();

        return $latest?->status === ApprovalRequestStatus::Rejected;
    }

    /**
     * A requisition that is saved again or taken back starts a new round: an
     * open request is withdrawn, and an earlier approval or rejection stays in
     * the history of the round it belonged to. Call it inside a transaction with
     * the requisition locked.
     */
    public static function startNewRound(PurchaseRequisition $requisition, User $actor): void
    {
        $had = ApprovalRequest::query()
            ->where('company_id', $requisition->company_id)
            ->where('subject_type', ApprovalSubjectType::PurchaseRequisition->value)
            ->where('subject_id', self::subjectId($requisition))
            ->exists();

        if ($had) {
            $requisition->loadMissing('rows');
            app(ApprovalEngine::class)->withdraw(SubjectFacts::forPurchaseRequisition($requisition), $actor);
        }

        $requisition->forceFill([
            'approval_round' => $requisition->approval_round + 1,
            'approval_request_id' => null,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'reject_reason' => null,
        ])->save();

        if ($had) {
            Audit::record('inventory.purchase_requisition_approval_reset', $requisition, ['number' => $requisition->number, 'round' => $requisition->approval_round], $requisition->company_id, $actor);
        }
    }
}
