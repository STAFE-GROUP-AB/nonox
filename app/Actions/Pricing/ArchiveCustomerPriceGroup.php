<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceListPriceGroup;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Archives a customer price group or brings it back. A group with customers,
 * or one a campaign is aimed at, is not archived: removing it from a campaign
 * would widen the campaign to everyone, and its customers would lose their
 * list without anyone choosing it.
 */
class ArchiveCustomerPriceGroup
{
    public function __invoke(User $user, Company $company, CustomerPriceGroup $group, bool $archive = true): CustomerPriceGroup
    {
        if ((int) $group->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $group);

        return DB::transaction(fn (): CustomerPriceGroup => CurrentCompany::run($company, function () use ($company, $user, $group, $archive): CustomerPriceGroup {
            if (! $archive) {
                $group->update(['archived_at' => null]);
                Audit::record('customer_price_group.restored', $group, [], $company, $user);

                return $group;
            }

            $customers = Customer::query()->where('price_group_id', $group->id)->count();
            $campaigns = PriceListPriceGroup::query()->where('customer_price_group_id', $group->id)->count();

            if ($customers + $campaigns > 0) {
                throw ValidationException::withMessages(['group' => __('Gruppen har :customers kunder och är vald i :campaigns kampanjer. Flytta kunderna och ändra kampanjerna först.', ['customers' => $customers, 'campaigns' => $campaigns])]);
            }

            $group->update(['archived_at' => CarbonImmutable::now()]);
            Audit::record('customer_price_group.archived', $group, [], $company, $user);

            return $group;
        }));
    }
}
