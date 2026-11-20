<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceFloorApprovalStatus;
use App\Domain\Pricing\PriceFloorEligibility;
use App\Domain\Pricing\PriceFloorFingerprint;
use App\Models\Company;
use App\Models\PriceFloorApproval;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves or rejects the prices under the floor on one version of an
 * invoice, quote or order (ADR-082 §9). Only someone with
 * prices:approve-below-floor, and not the person who wrote the document or
 * asked for the approval while the company requires separate approval
 * (PriceFloorEligibility). A document changed since the request cannot be
 * approved: the request is asked for again. A rejection needs a
 * reason. Another company's approval is not found.
 */
class DecidePriceFloorApproval
{
    public const int REASON_MAX = 500;

    public function __invoke(User $user, Company $company, int $approvalId, bool $approve, ?string $reason = null): PriceFloorApproval
    {
        $reason = trim((string) $reason);

        return CurrentCompany::run($company, fn (): PriceFloorApproval => DB::transaction(function () use ($user, $company, $approvalId, $approve, $reason): PriceFloorApproval {
            $approval = PriceFloorApproval::query()->whereKey($approvalId)->lockForUpdate()->firstOrFail();
            $document = $approval->document_type->find($approval->document_id);

            $refusal = PriceFloorEligibility::refusal($user, $company, $document, $approval);

            if ($refusal === PriceFloorEligibility::NOT_APPROVER || $company->isArchived()) {
                throw new AuthorizationException(__('Du har inte behörighet att godkänna priser under lägsta täckningsgrad.'));
            }

            if ($refusal === PriceFloorEligibility::WROTE_IT) {
                throw ValidationException::withMessages(['approval' => __('Du kan inte godkänna priser på ett underlag som du har skrivit, ändrat eller begärt godkännande för. Be någon annan.')]);
            }

            if ($approval->status !== PriceFloorApprovalStatus::Pending) {
                throw ValidationException::withMessages(['approval' => __('Begäran är redan avgjord.')]);
            }

            // The request for the new version withdraws this one (RequestPriceFloorApproval); nothing is written here.
            if (PriceFloorFingerprint::of($document) !== $approval->fingerprint) {
                throw ValidationException::withMessages(['approval' => __('Raderna har ändrats sedan godkännande begärdes. Be om godkännande igen.')]);
            }

            if (! $approve && $reason === '') {
                throw ValidationException::withMessages(['reason' => __('Skriv varför priserna avslås.')]);
            }

            if (mb_strlen($reason) > self::REASON_MAX) {
                throw ValidationException::withMessages(['reason' => __('Skriv högst :max tecken.', ['max' => self::REASON_MAX])]);
            }

            $approval->forceFill([
                'status' => $approve ? PriceFloorApprovalStatus::Approved : PriceFloorApprovalStatus::Rejected,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'reason' => $reason === '' ? null : $reason,
            ])->save();

            Audit::record($approve ? 'price_floor.approved' : 'price_floor.rejected', $approval, [
                'document_type' => $approval->document_type->value,
                'document_id' => $approval->document_id,
            ], $company, $user);

            return $approval;
        }));
    }
}
