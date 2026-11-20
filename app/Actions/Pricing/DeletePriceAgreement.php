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

            $agreement->items()->get()->each->delete();
            $agreement->delete();
            Audit::record('price_agreement.deleted', null, ['price_agreement_id' => $agreement->id, 'customer_id' => $agreement->customer_id], $company, $user);
        }));
    }
}
