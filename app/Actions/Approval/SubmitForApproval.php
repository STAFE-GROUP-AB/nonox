<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Domain\Approval\DraftApprovals;
use App\Domain\Approval\DraftFingerprint;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a subject to approval under the company's own policy (ADR-081 E21):
 * a manual voucher draft before it is posted, a credit invoice draft before
 * it is issued. Whoever prepared it, or anyone who may prepare such
 * subjects, sends it. A rejected version is sent again; an open request on
 * an earlier version of the same draft is withdrawn, since that version no
 * longer exists. The subject id names the version the sender saw.
 */
class SubmitForApproval
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, ApprovalSubjectType $type, string $subjectId): ApprovalRequest
    {
        return CurrentCompany::run($company, fn (): ApprovalRequest => DB::transaction(function () use ($user, $company, $type, $subjectId): ApprovalRequest {
            $subject = ApprovalSubjects::lock($type, $subjectId);
            $facts = ApprovalSubjects::facts($subject);

            if (! in_array($user->id, $facts->preparerIds, true) && ! $user->hasTeamPermission($company, ApprovalSubjects::preparePermission($type))) {
                throw new AuthorizationException(__('Bara den som förberedde underlaget kan skicka det till attest.'));
            }

            if (! ApprovalSubjects::awaiting($subject)) {
                throw ValidationException::withMessages(['approve' => __('Underlaget väntar inte på attest.')]);
            }

            $request = $this->engine->submit(ApprovalContext::load($company), $user, $facts);

            $parsed = DraftFingerprint::parse($facts->subjectId);

            if ($parsed !== null) {
                DraftApprovals::withdrawOtherVersions($type, $company->id, $parsed[0], $facts->subjectId, $user);
            }

            return $request;
        }));
    }
}
