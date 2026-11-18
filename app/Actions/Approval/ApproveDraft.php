<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalDecisionKind;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DecisionOutcome;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Approval\DraftApprovalStatus;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Approves a manual voucher draft or a credit invoice draft on the current
 * step of the company's own policy (ADR-081 E21). The draft is not changed:
 * it stays a draft, without a number, until a person posts or issues it, and
 * the approval holds only for the version approved (the subject id names
 * it, and it is checked again under the draft's row lock).
 */
class ApproveDraft
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, ApprovalSubjectType $type, string $subjectId): DecisionOutcome
    {
        if ($type->hasDefaultApproval()) {
            throw new InvalidArgumentException("{$type->value} is approved by its own action.");
        }

        return CurrentCompany::run($company, fn (): DecisionOutcome => DB::transaction(function () use ($user, $company, $type, $subjectId): DecisionOutcome {
            $subject = ApprovalSubjects::lock($type, $subjectId);

            if (! ApprovalSubjects::awaiting($subject)) {
                throw ValidationException::withMessages(['approve' => __('Underlaget väntar inte på attest.')]);
            }

            $facts = ApprovalSubjects::facts($subject);

            if (DraftApprovals::status($type, $company->id, $facts->subjectId, fn () => $facts) === DraftApprovalStatus::Approved) {
                throw ValidationException::withMessages(['approve' => __('Underlaget är redan attesterat.')]);
            }

            $outcome = $this->engine->decide(ApprovalContext::load($company), $user, $facts, ApprovalDecisionKind::Approve);

            if ($outcome === DecisionOutcome::Default) {
                throw ValidationException::withMessages(['approve' => __('Inget attestflöde gäller underlaget, så det behöver ingen attest.')]);
            }

            // A decision opens the version's request when nobody sent it: an open request on an earlier version is over.
            DraftApprovals::withdrawOtherVersions($type, $company->id, (string) $subject->getKey(), $facts->subjectId, $user);

            return $outcome;
        }));
    }
}
