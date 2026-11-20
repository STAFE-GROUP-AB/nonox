<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Actions\Signing\SendForSigning;
use App\Domain\Pricing\PriceAgreementStatus;
use App\Domain\Signing\SigningStatus;
use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\SigningRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * "Tillbaka till utkast" (ADR-082 P5): an agreement awaiting a signature
 * becomes a draft again so its terms can change. Every open signing link is
 * revoked first, so nobody can sign a version that is about to change; a
 * link answers that it has been revoked. Used after a decline, when a link
 * has run out, or when the customer asks for a change.
 */
class ReopenPriceAgreement
{
    public function __construct(private readonly SendForSigning $send) {}

    public function __invoke(User $user, Company $company, PriceAgreement $agreement): PriceAgreement
    {
        if ((int) $agreement->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $agreement);

        return DB::transaction(fn (): PriceAgreement => CurrentCompany::run($company, function () use ($user, $company, $agreement): PriceAgreement {
            $locked = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PriceAgreementStatus::AwaitingSignature) {
                throw ValidationException::withMessages(['agreement' => __('Bara ett avtal som väntar på underskrift kan tas tillbaka till utkast.')]);
            }

            // Revoking one link also withdraws its envelope's other links, so look again after each.
            while (($open = $this->openRequest($locked)) instanceof SigningRequest) {
                $this->send->revoke($user, $company, $open);
            }

            $locked->forceFill(['status' => PriceAgreementStatus::Draft])->save();
            Audit::record('price_agreement.reopened', $locked, ['price_agreement_id' => $locked->id], $company, $user);

            return $locked;
        }));
    }

    private function openRequest(PriceAgreement $agreement): ?SigningRequest
    {
        return SigningRequest::query()
            ->where('document_type', PriceAgreement::SIGNING_TYPE)
            ->where('signable_id', (string) $agreement->id)
            ->where('status', SigningStatus::Pending->value)
            ->orderBy('id')
            ->first();
    }
}
