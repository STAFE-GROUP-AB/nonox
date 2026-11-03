<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Domain\AccountKind;
use App\Modules\Crm\Domain\AccountStatus;
use App\Modules\Crm\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Gives every customer of the company an account (ADR-043), when the module
 * is turned on: one insert for all customers without one, then the status
 * rules. Idempotent: a customer that has an account is left alone. A
 * customer added later gets its account from CustomerAccounts, the
 * observer on the customer register.
 */
final class EnsureCustomerAccounts
{
    /** @return int the number of accounts created */
    public function __invoke(Company $company, ?User $by = null): int
    {
        return CurrentCompany::run($company, function () use ($company, $by): int {
            $created = DB::transaction(function (): int {
                $missing = Customer::query()
                    ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('crm_accounts')->whereColumn('crm_accounts.customer_id', 'customers.id'))
                    ->toBase()
                    ->select([
                        'company_id',
                        'id',
                        'name',
                        'org_number',
                        DB::raw("CASE WHEN personal_number IS NULL THEN '".AccountKind::Company->value."' ELSE '".AccountKind::Person->value."' END"),
                        DB::raw("'".AccountStatus::Prospect->value."'"),
                        'email',
                        'phone',
                        'address_line1',
                        'postal_code',
                        'city',
                        'country',
                        DB::raw('NOW()'),
                        DB::raw('NOW()'),
                    ]);

                // One insert for every customer at once; the query is scoped to the company.
                return Account::query()->toBase()->insertUsing(
                    ['company_id', 'customer_id', 'name', 'org_number', 'kind', 'status', 'email', 'phone', 'address_line1', 'postal_code', 'city', 'country', 'created_at', 'updated_at'],
                    $missing,
                );
            });

            if ($created > 0) {
                Audit::record('crm.accounts_created_from_customers', null, ['count' => $created], $company, $by);
                app(RefreshStatuses::class)($company);
            }

            return $created;
        });
    }

    /** One customer's account, for a customer added while the module is on. */
    public function forCustomer(Customer $customer): ?Account
    {
        return CurrentCompany::run($customer->company_id, function () use ($customer): ?Account {
            if (Account::query()->where('customer_id', $customer->id)->exists()) {
                return null;
            }

            return Account::create([
                'customer_id' => $customer->id,
                'kind' => $customer->personal_number === null ? AccountKind::Company : AccountKind::Person,
                'status' => AccountStatus::Prospect,
                ...self::sharedFields($customer),
            ]);
        });
    }

    /**
     * The fields an account takes from its customer.
     *
     * @return array<string, mixed>
     */
    public static function sharedFields(Customer $customer): array
    {
        return [...$customer->only(Account::CUSTOMER_FIELDS), 'country' => $customer->country ?: 'SE'];
    }
}
