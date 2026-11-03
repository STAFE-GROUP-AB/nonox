<?php

declare(strict_types=1);

namespace App\Modules\Crm\Actions;

use App\Actions\Invoicing\SaveCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Support\CustomerAccounts;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns a prospect into a customer (ADR-043): the customer register's own
 * action creates the customer with the next number from the customer
 * series, from the account's name, number and address, and the account is
 * linked to it. The status stays until the first invoice makes it active.
 * Needs both crm:manage and the right to add customers (invoices:create).
 */
final class ConvertToCustomer
{
    public function __construct(private readonly SaveCustomer $saveCustomer) {}

    public function __invoke(User $user, Company $company, Account $account, int $paymentTermsDays = 30): Customer
    {
        Gate::forUser($user)->authorize('update', $account);

        if ($account->customer_id !== null) {
            throw ValidationException::withMessages(['account' => __('Det här är redan en kund.')]);
        }

        return DB::transaction(fn (): Customer => CurrentCompany::run($company, function () use ($user, $company, $account, $paymentTermsDays): Customer {
            $customer = CustomerAccounts::withoutNewAccounts(fn (): Customer => ($this->saveCustomer)($user, $company, [
                ...$account->only(Account::CUSTOMER_FIELDS),
                'payment_terms_days' => $paymentTermsDays,
            ]));

            $account->forceFill(['customer_id' => $customer->id, 'last_activity_at' => now()])->save();

            Audit::record('crm.converted_to_customer', $account, ['customer_id' => $customer->id, 'number' => $customer->number], $company, $user);

            return $customer;
        }));
    }
}
