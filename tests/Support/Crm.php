<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Person;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * CRM in tests (ADR-043): the module on, customers, prospects, people and
 * issued invoices.
 */
final class Crm
{
    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(CrmModule::KEY, $company, $owner);
        CurrentCompany::set($company);
    }

    /** @param  array<string, mixed>  $extra */
    public static function customer(Company $company, User $owner, string $name = 'Kontorshuset AB', array $extra = []): Customer
    {
        return app(SaveCustomer::class)($owner, $company, ['name' => $name, 'payment_terms_days' => 30, ...$extra]);
    }

    public static function accountFor(Customer $customer): Account
    {
        return Account::query()->where('customer_id', $customer->id)->firstOrFail();
    }

    /** @param  array<string, mixed>  $extra */
    public static function prospect(Company $company, User $owner, string $name = 'Prospektet AB', array $extra = []): Account
    {
        return app(SaveAccount::class)($owner, $company, ['name' => $name, ...$extra]);
    }

    /** @param  array<string, mixed>  $extra */
    public static function person(Company $company, User $owner, string $name = 'Anna Andersson', array $extra = []): Person
    {
        return app(SavePerson::class)($owner, $company, ['name' => $name, 'legal_basis' => 'LEGITIMATE_INTEREST', ...$extra]);
    }

    /**
     * An issued invoice (or credit invoice) with the given rows.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function invoice(User $owner, Company $company, Customer $customer, string $date, array $rows, ?string $due = null): Invoice
    {
        return app(IssueInvoice::class)($owner, app(SaveDraftInvoice::class)($owner, $company, array_filter([
            'customer_id' => $customer->id,
            'invoice_date' => $date,
            'due_date' => $due,
            'rows' => $rows,
        ], fn (mixed $value): bool => $value !== null)));
    }
}
