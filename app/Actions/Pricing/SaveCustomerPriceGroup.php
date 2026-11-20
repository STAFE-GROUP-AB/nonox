<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Domain\Pricing\PriceListKind;
use App\Models\Company;
use App\Models\CustomerPriceGroup;
use App\Models\PriceList;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a customer price group (ADR-082 §3): "Återförsäljare",
 * "Offentlig sektor", with the price list its customers get. The list must
 * be an ordinary price list of the company.
 */
class SaveCustomerPriceGroup
{
    /** @param  array<string, mixed>  $input  code, name, price_list_id (null = none) */
    public function __invoke(User $user, Company $company, array $input, ?CustomerPriceGroup $group = null): CustomerPriceGroup
    {
        if ($group !== null && (int) $group->company_id !== (int) $company->id) {
            throw new ModelNotFoundException;
        }

        $group === null
            ? Gate::forUser($user)->authorize('create', [CustomerPriceGroup::class, $company])
            : Gate::forUser($user)->authorize('update', $group);

        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));

        $validated = Validator::make($input, [
            'code' => ['required', 'string', 'max:20', Rule::unique('customer_price_groups', 'code')->where('company_id', $company->id)->ignore($group?->id)],
            'name' => ['required', 'string', 'max:100'],
            'price_list_id' => ['nullable', 'integer'],
        ], [
            'code.unique' => __('Koden :code används redan av en annan kundgrupp.', ['code' => $input['code']]),
            'code.max' => __('Koden får vara högst 20 tecken.'),
        ], ['code' => __('kod'), 'name' => __('namn')])->validate();

        return DB::transaction(fn (): CustomerPriceGroup => CurrentCompany::run($company, function () use ($validated, $company, $user, $group): CustomerPriceGroup {
            $listId = $validated['price_list_id'] ?? null;

            if ($listId !== null && $listId !== '') {
                $list = PriceList::query()->whereKey((int) $listId)->whereNull('archived_at')->where('kind', PriceListKind::Standard->value)->first()
                    ?? throw ValidationException::withMessages(['price_list_id' => __('Välj en vanlig prislista.')]);
                $listId = $list->id;
            } else {
                $listId = null;
            }

            $attributes = ['code' => $validated['code'], 'name' => trim((string) $validated['name']), 'price_list_id' => $listId];

            if ($group === null) {
                $group = CustomerPriceGroup::create($attributes);
                Audit::record('customer_price_group.created', $group, ['price_list_id' => $listId], $company, $user);
            } else {
                $group->update($attributes);
                Audit::record('customer_price_group.updated', $group, ['price_list_id' => $listId, 'fields' => array_keys($group->getChanges())], $company, $user);
            }

            return $group;
        }));
    }
}
