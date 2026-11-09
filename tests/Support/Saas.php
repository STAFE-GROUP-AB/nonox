<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\RunRecurringInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveRecurringInvoice;
use App\Domain\Invoicing\AgreementStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Saas\Models\SaasMonth;
use App\Modules\Saas\SaasModule;
use App\Modules\Saas\Support\SaasLedger;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * NONOX SaaS test setup (ADR-080). Every company, customer and amount here
 * is synthetic.
 */
final class Saas
{
    public static function enable(Company $company, User $owner): void
    {
        app(ModuleManager::class)->enable(SaasModule::KEY, $company, $owner);
        CurrentCompany::set($company);
    }

    public static function customer(User $owner, Company $company, string $name = 'Molntjänst Test AB'): Customer
    {
        return app(SaveCustomer::class)($owner, $company, ['name' => $name, 'payment_terms_days' => 30]);
    }

    /**
     * An agreement of one row: the price per period in kronor (excl. VAT).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function agreement(User $owner, Company $company, Customer $customer, string $price, int $months = 1, string $next = '2026-01-01', array $overrides = []): RecurringInvoice
    {
        return app(SaveRecurringInvoice::class)($owner, $company, [
            'customer_id' => $customer->id,
            'name' => 'Abonnemang',
            'interval_months' => $months,
            'next_invoice_date' => $next,
            'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
            ...$overrides,
        ]);
    }

    /** Changes the agreement's price, effective from a date. */
    public static function reprice(User $owner, Company $company, RecurringInvoice $agreement, string $price, ?string $from = null): RecurringInvoice
    {
        $agreement->loadMissing('rows');

        return app(SaveRecurringInvoice::class)($owner, $company, [
            'customer_id' => $agreement->customer_id,
            'name' => $agreement->name,
            'interval_months' => $agreement->interval_months,
            'next_invoice_date' => $agreement->next_invoice_date->toDateString(),
            'end_date' => $agreement->end_date?->toDateString(),
            'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500]],
            'effective_from' => $from,
        ], $agreement);
    }

    public static function status(User $owner, RecurringInvoice $agreement, AgreementStatus $status, ?string $from = null): RecurringInvoice
    {
        return app(RunRecurringInvoice::class)->setStatus($owner, $agreement, $status, $from);
    }

    public static function recompute(Company $company): void
    {
        app(SaasLedger::class)->recompute($company);
    }

    public static function month(string $month): SaasMonth
    {
        return SaasMonth::query()->where('month', $month)->firstOrFail();
    }

    public static function at(string $date): void
    {
        test()->travelTo(CarbonImmutable::parse("{$date} 10:00:00"));
    }
}
