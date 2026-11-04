<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * Synthetic companies, customers and invoices for the Peppol tests. Every
 * organisation number, VAT number and payment account here is made up
 * (Luhn-valid, not taken from any real company).
 */
final class PeppolFixtures
{
    public const string SELLER_ORG = '5599996013';

    public const string BUYER_ORG = '5563343689';

    /** @return array{0: User, 1: Company} */
    public static function company(): array
    {
        test()->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
        [$owner, $company] = Bookkeeping::company();
        $company->forceFill([
            'name' => 'Exempelbolaget',
            'legal_name' => 'Exempelbolaget AB',
            'org_number' => '559999-6013',
            'vat_number' => 'SE559999601301',
            'address_line1' => 'Provgatan 1',
            'address_line2' => null,
            'postal_code' => '111 11',
            'city' => 'Stockholm',
            'country' => 'SE',
            'email' => 'faktura@exempel.test',
            'phone' => '08-000 00 00',
            'f_skatt' => true,
            'bankgiro' => '123-4567',
            'plusgiro' => null,
            'iban' => 'SE4550000000058398257466',
            'bic' => 'ESSESESS',
        ])->save();
        CurrentCompany::set($company);
        test()->actingAs($owner);

        return [$owner->fresh(), $company->fresh()];
    }

    /** @param array<string, mixed> $attributes */
    public static function customer(User $owner, Company $company, array $attributes = []): Customer
    {
        return app(SaveCustomer::class)($owner, $company, [
            'name' => 'Kundbolaget AB',
            'org_number' => '556334-3689',
            'vat_number' => 'SE556334368901',
            'address_line1' => 'Kundvägen 2',
            'postal_code' => '222 22',
            'city' => 'Malmö',
            'country' => 'SE',
            'payment_terms_days' => 30,
            ...$attributes,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extra
     */
    public static function invoice(User $owner, Company $company, Customer $customer, array $rows, array $extra = []): Invoice
    {
        $draft = app(SaveDraftInvoice::class)($owner, $company, [
            'customer_id' => $customer->id,
            'invoice_date' => '2026-03-02',
            'your_reference' => 'Ref 4711',
            'our_reference' => 'Testsäljare',
            'rows' => $rows,
            ...$extra,
        ]);

        return app(IssueInvoice::class)($owner, $draft)->fresh(['rows', 'credits']);
    }

    public static function credit(User $owner, Invoice $invoice): Invoice
    {
        $draft = app(CreditInvoice::class)($owner, $invoice);

        return app(IssueInvoice::class)($owner, $draft)->fresh(['rows', 'credits']);
    }
}
