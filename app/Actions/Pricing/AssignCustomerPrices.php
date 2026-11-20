<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceListKind;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sets a customer's price group and own price list (ADR-082 §3). The
 * customer's own list wins over the group's. Both ids must belong to the
 * company, the group must not be archived and the list must be an ordinary,
 * unarchived price list.
 */
class AssignCustomerPrices
{
    public function __invoke(User $user, Company $company, Customer $customer, ?int $groupId, ?int $listId): Customer
    {
        if ((int) $customer->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('create', [PriceList::class, $company]);

        return DB::transaction(fn (): Customer => CurrentCompany::run($company, function () use ($company, $user, $customer, $groupId, $listId): Customer {
            if ($groupId !== null && ! CustomerPriceGroup::query()->whereKey($groupId)->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $customer->price_group_id))->exists()) {
                throw ValidationException::withMessages(['price_group_id' => __('Kundgruppen finns inte.')]);
            }

            if ($listId !== null && ! PriceList::query()->whereKey($listId)->where('kind', PriceListKind::Standard->value)->where(fn ($query) => $query->whereNull('archived_at')->orWhere('id', $customer->price_list_id))->exists()) {
                throw ValidationException::withMessages(['price_list_id' => __('Välj en vanlig prislista.')]);
            }

            if ($listId !== null && PriceList::query()->whereKey($listId)->value('currency') !== $customer->currency) {
                throw ValidationException::withMessages(['price_list_id' => __('Listan är i en annan valuta än kundens (:currency). Välj en lista i kundens valuta.', ['currency' => $customer->currency])]);
            }

            $customer->update(['price_group_id' => $groupId, 'price_list_id' => $listId]);
            Audit::record('customer.prices_assigned', $customer, ['price_group_id' => $groupId, 'price_list_id' => $listId], $company, $user);

            return $customer;
        }));
    }
}
