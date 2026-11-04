<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use App\Models\Customer;
use App\Modules\Crm\Actions\EnsureCustomerAccounts;
use App\Modules\Crm\Calendar\CalendarSequence;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * Keeps the CRM in step with the customer register while the module is on
 * (ADR-043): a new customer, however it was added (the register, an
 * invoice, the API, an import), gets an account, and a changed customer's
 * name, numbers and address follow to its account. An observer, so no
 * code path that creates customers has to know about the CRM.
 */
final class CustomerAccounts
{
    private static bool $paused = false;

    /**
     * Runs $callback without creating accounts for new customers, for the
     * action that turns an existing account into a customer.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutNewAccounts(callable $callback): mixed
    {
        $before = self::$paused;
        self::$paused = true;

        try {
            return $callback();
        } finally {
            self::$paused = $before;
        }
    }

    public function created(Customer $customer): void
    {
        if (self::$paused || ! app(ModuleManager::class)->isEnabled(CrmModule::KEY, $customer->company_id)) {
            return;
        }

        app(EnsureCustomerAccounts::class)->forCustomer($customer);
    }

    public function updated(Customer $customer): void
    {
        if (! $customer->wasChanged(Account::CUSTOMER_FIELDS)) {
            return;
        }

        // Kept in step even while the module is off, so the account is right when it is turned on again.
        CurrentCompany::run($customer->company_id, function () use ($customer): void {
            Account::query()
                ->where('customer_id', $customer->id)
                ->update([...EnsureCustomerAccounts::sharedFields($customer), 'updated_at' => now()]);

            // ADR-049: a renamed customer is renamed in calendars too.
            if ($customer->wasChanged('name')) {
                Account::query()->where('customer_id', $customer->id)->pluck('id')->each(fn (int $id) => CalendarSequence::forAccount($id));
            }
        });
    }

    /**
     * A customer is deleted only when it is erased under GDPR and was never
     * booked; its account holds a copy of the same data and goes with it.
     */
    public function deleting(Customer $customer): void
    {
        // One by one, so the account's own deleting event removes its agreements' files (SigningFiles).
        CurrentCompany::run($customer->company_id, fn () => Account::query()->where('customer_id', $customer->id)->get()->each->delete());
    }
}
