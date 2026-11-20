<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Company;
use App\Models\PriceAgreement;
use App\Models\PriceAgreementItem;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Removes one term from a draft price agreement (ADR-082 P5). A sent, active
 * or closed agreement's terms are frozen: the model, the policy and a
 * database trigger all refuse it.
 */
class RemovePriceAgreementItem
{
    public function __invoke(User $user, Company $company, PriceAgreement $agreement, PriceAgreementItem $item): void
    {
        if ((int) $agreement->company_id !== (int) $company->id || (int) $item->price_agreement_id !== (int) $agreement->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('changeTerms', $agreement);

        DB::transaction(fn () => CurrentCompany::run($company, function () use ($company, $user, $agreement, $item): void {
            $agreement = PriceAgreement::query()->whereKey($agreement->id)->lockForUpdate()->firstOrFail();

            if (! $agreement->status->isDraft()) {
                throw ValidationException::withMessages(['agreement' => __('Avtalet är inte längre ett utkast. Gör en ny version med Ändra avtal.')]);
            }

            PriceAgreementItem::query()->whereKey($item->id)->firstOrFail()->delete();
            Audit::record('price_agreement_item.deleted', $agreement, ['price_agreement_id' => $agreement->id, 'price_agreement_item_id' => $item->id], $company, $user);
        }));
    }
}
