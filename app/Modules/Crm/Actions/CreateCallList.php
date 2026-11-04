<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\RegistryCompany;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\CallList;
use App\Modules\Crm\Models\CallListItem;
use App\Modules\Crm\Support\CompanyMembers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Makes a call list (ADR-047) of the company's accounts, in the order
 * given, for one seller. Accounts are left out when the company has a
 * telemarketing or NIX opt-out in the register (SCB's API), when they are
 * lost, or when their customer is inactive or restricted; the result says
 * how many. At most CallList::MAX_ITEMS accounts.
 */
final class CreateCallList
{
    /**
     * @param  array{name?: mixed, assigned_to?: mixed}  $input
     * @param  list<int>  $accountIds
     * @param  array<string, mixed>  $criteria  how the list was built, shown on the list
     * @return array{list: CallList, left_out: int}
     */
    public function __invoke(User $user, Company $company, array $input, array $accountIds, array $criteria = []): array
    {
        Gate::forUser($user)->authorize('manage', [Account::class, $company]);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'integer', Rule::in(CompanyMembers::ids($company))],
        ], [
            'assigned_to.in' => __('Ringlistan kan bara ges till någon som är med i företaget.'),
        ], [
            'name' => __('namn'),
        ])->validate();

        $accountIds = array_values(array_unique(array_map('intval', $accountIds)));

        if ($accountIds === []) {
            throw ValidationException::withMessages(['selection' => __('Ringlistan behöver minst ett företag.')]);
        }

        if (count($accountIds) > CallList::MAX_ITEMS) {
            throw ValidationException::withMessages(['selection' => __('En ringlista rymmer högst :max företag.', ['max' => CallList::MAX_ITEMS])]);
        }

        return DB::transaction(fn (): array => CurrentCompany::run($company, function () use ($user, $company, $validated, $accountIds, $criteria): array {
            $accounts = Account::query()
                ->whereKey($accountIds)
                ->where('status', '<>', 'LOST')
                ->where(fn ($query) => $query->whereNull('customer_id')->orWhereHas('customer', fn ($customer) => $customer->where('is_active', true)->whereNull('processing_restricted_at')))
                ->get(['id', 'org_number'])
                ->keyBy('id');

            $blocked = RegistryCompany::query()
                ->whereIn('phone_block', [2, 3])
                ->whereIn('org_number', $accounts->map(fn (Account $account): ?string => RegistryCompany::digits($account->org_number))->filter()->values()->all())
                ->pluck('org_number')
                ->flip();

            $list = CallList::create([
                'name' => trim((string) $validated['name']),
                'assigned_to' => $validated['assigned_to'] ?? $user->id,
                'created_by' => $user->id,
                'criteria' => $criteria === [] ? null : $criteria,
            ]);

            $position = 0;

            foreach ($accountIds as $id) {
                $account = $accounts->get($id);

                if (! $account instanceof Account || $blocked->has((string) RegistryCompany::digits($account->org_number))) {
                    continue;
                }

                CallListItem::create(['call_list_id' => $list->id, 'account_id' => $id, 'position' => ++$position]);
            }

            if ($position === 0) {
                throw ValidationException::withMessages(['selection' => __('Inget av företagen kan ringas: de är förlorade, inaktiva eller har spärrat telefonförsäljning.')]);
            }

            Audit::record('crm.call_list_created', $list, ['call_list_id' => $list->id, 'items' => $position, 'left_out' => count($accountIds) - $position], $company, $user);

            return ['list' => $list, 'left_out' => count($accountIds) - $position];
        }));
    }
}
