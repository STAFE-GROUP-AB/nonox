<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreementItem;
use App\Models\PriceList;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Archives a price list, or brings an archived one back (ADR-082 §11: used
 * prices are archived, not deleted). A list that customers, customer groups,
 * other lists or agreements still point at is not archived: the person is
 * told what to change first, so no customer silently loses its prices.
 */
class ArchivePriceList
{
    public function __invoke(User $user, Company $company, PriceList $list, bool $archive = true): PriceList
    {
        if ((int) $list->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        Gate::forUser($user)->authorize('update', $list);

        return DB::transaction(fn (): PriceList => CurrentCompany::run($company, function () use ($company, $user, $list, $archive): PriceList {
            if (! $archive) {
                $list->update(['archived_at' => null]);
                Audit::record('price_list.restored', $list, [], $company, $user);

                return $list;
            }

            $this->refuseWhileInUse($list);

            $list->update(['archived_at' => CarbonImmutable::now(), 'is_default_for_currency' => false]);
            Audit::record('price_list.archived', $list, [], $company, $user);

            return $list;
        }));
    }

    private function refuseWhileInUse(PriceList $list): void
    {
        $customers = Customer::query()->where('price_list_id', $list->id)->count();
        $groups = CustomerPriceGroup::query()->where('price_list_id', $list->id)->whereNull('archived_at')->count();
        $followers = PriceList::query()->where('based_on_list_id', $list->id)->whereNull('archived_at')->count();
        $agreements = PriceAgreementItem::query()->where('base_price_list_id', $list->id)->count();

        if ($customers + $groups + $followers + $agreements === 0) {
            return;
        }

        throw ValidationException::withMessages(['list' => __('Listan används av :customers kunder, :groups kundgrupper, :followers andra listor och :agreements prisavtal. Byt lista på dem först, så ingen förlorar sina priser.', [
            'customers' => $customers, 'groups' => $groups, 'followers' => $followers, 'agreements' => $agreements,
        ])]);
    }
}
