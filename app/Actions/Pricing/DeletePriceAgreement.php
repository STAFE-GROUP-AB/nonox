<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a draft price agreement with its terms (ADR-082 P5). An agreement
 * that has been sent, signed or activated is never deleted (policy, model
 * event and a database trigger); it ends, is terminated or is superseded.
 * Nor is a draft that was once sent for signing: its signing requests and
 * envelopes point at it, so it stays (a draft never prices anything).
 */
class DeletePriceAgreement
{
    public function __invoke(User $user, Company $company, PriceAgreement $agreement): void
    {
        if ((int) $agreement->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('delete', $agreement);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($company, $user, $agreement): void {
            $agreement = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->status->isDraft()) {
                throw ValidationException::withMessages(['agreement' => __('Bara ett utkast kan tas bort. Ett avtal som har skickats eller gällt finns kvar i historiken.')]);
            }

            // A draft that was once sent keeps its signing history, which points at it.
            if ($agreement->hasBeenSent()) {
                throw ValidationException::withMessages(['agreement' => __('Utkastet har skickats för signering och finns kvar tillsammans med signeringens historik. Det kan inte tas bort, men det gäller aldrig utan att du skickar eller aktiverar det.')]);
            }

            $agreement->items()->get()->each->delete();
            $agreement->delete();
            Audit::record('price_agreement.deleted', null, ['price_agreement_id' => $agreement->id, 'customer_id' => $agreement->customer_id], $company, $user);
        }));
    }
}
