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
use App\Modules\Crm\Actions\SaveDeal;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\Models\Account;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\PipelineStage;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;

/**
 * CRM in tests (ADR-043): the module on, customers, prospects, people,
 * issued invoices, and in stage 2 deals with their next step.
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

    /**
     * An open deal with its next step (a task tomorrow at nine), in the
     * default pipeline's first stage unless a stage is given.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function deal(Company $company, User $owner, Account $account, string $title = 'Ramavtal', int $valueOre = 1_000_000, array $extra = []): Deal
    {
        return app(SaveDeal::class)($owner, $company, [
            'account_id' => $account->id,
            'title' => $title,
            'value_ore' => $valueOre,
            'next_step' => ['type' => 'CALL', 'subject' => 'Följ upp', 'due_at' => now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i')],
            ...$extra,
        ]);
    }

    public static function pipeline(): Pipeline
    {
        return Pipeline::query()->where('is_default', true)->firstOrFail();
    }

    /** The default pipeline's stage at $index (0 is the first). */
    public static function stage(int $index = 0): PipelineStage
    {
        return self::pipeline()->stages()->get()->values()[$index];
    }
}
