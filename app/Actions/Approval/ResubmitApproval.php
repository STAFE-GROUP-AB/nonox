<?php

declare(strict_types=1);

namespace App\Actions\Approval;

use App\Domain\Approval\ApprovalContext;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\ApprovalSubjects;
use App\Domain\Approval\ApprovalSubjectType;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Purchasing\ResubmitPurchaseRequisition;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a rejected subject to approval again (ADR-081 gap 8). The preparer
 * does it, or anyone who may prepare such subjects. The new request follows
 * the company's policy as it is now; the rejected one stays in the history.
 */
class ResubmitApproval
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    public function __invoke(User $user, Company $company, ApprovalSubjectType $type, string $subjectId): void
    {
        if ($type === ApprovalSubjectType::PurchaseRequisition) {
            app(ResubmitPurchaseRequisition::class)($user, $company, $subjectId);

            return;
        }

        CurrentCompany::run($company, function () use ($user, $company, $type, $subjectId): void {
            DB::transaction(function () use ($user, $company, $type, $subjectId): void {
                $subject = ApprovalSubjects::lock($type, $subjectId);
                $facts = ApprovalSubjects::facts($subject);

                if (! in_array($user->id, $facts->preparerIds, true) && ! $user->hasTeamPermission($company, ApprovalSubjects::preparePermission($type))) {
                    throw new AuthorizationException(__('Bara den som förberedde underlaget kan skicka det till attest igen.'));
                }

                if (! ApprovalSubjects::awaiting($subject)) {
                    throw ValidationException::withMessages(['approve' => __('Underlaget väntar inte på attest.')]);
                }

                $this->engine->resubmit(ApprovalContext::load($company), $user, $facts);
            });
        });
    }
}
