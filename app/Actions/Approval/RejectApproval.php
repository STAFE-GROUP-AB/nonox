<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDecisionKind;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Approval\DraftApprovalStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Purchasing\RejectPurchaseRequisition;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rejects a subject on the current step of its company's own approval
 * policy, with an optional reason (ADR-081 gap 8). The request ends as
 * rejected and the subject goes back to its preparer, who can send it to
 * approval again or deal with it (a credit note, a cancelled payment file).
 * The subject itself is not changed. The default policy has no rejection,
 * as today.
 */
class RejectApproval
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, ApprovalSubjectType $type, string $subjectId, ?string $reason = null): void
    {
        // A requisition's own status says it was rejected, in the default flow too (ADR-081 E9).
        if ($type === ApprovalSubjectType::PurchaseRequisition) {
            app(RejectPurchaseRequisition::class)($user, $company, $subjectId, $reason);

            return;
        }

        CurrentCompany::run($company, function () use ($user, $company, $type, $subjectId, $reason): void {
            DB::transaction(function () use ($user, $company, $type, $subjectId, $reason): void {
                $subject = ApprovalSubjects::lock($type, $subjectId);

                if (! ApprovalSubjects::awaiting($subject)) {
                    throw ValidationException::withMessages(['approve' => __('Underlaget väntar inte på attest.')]);
                }

                $facts = ApprovalSubjects::facts($subject);

                // A draft version already approved (ADR-081 E21) is not reopened by a rejection; a changed draft is a new version.
                if (! $type->hasDefaultApproval() && DraftApprovals::status($type, $company->id, $facts->subjectId, fn () => $facts) === DraftApprovalStatus::Approved) {
                    throw ValidationException::withMessages(['approve' => __('Underlaget är redan attesterat.')]);
                }

                $this->engine->decide(ApprovalContext::load($company), $user, $facts, ApprovalDecisionKind::Reject, $reason);
            });
        });
    }
}
