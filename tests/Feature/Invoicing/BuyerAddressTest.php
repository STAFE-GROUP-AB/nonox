<?php

declare(strict_types=1);

/*
 * A full invoice carries the buyer's full name and address (Mervärdesskattelag
 * (2023:200) 17 kap. 24 § p. 6). A simplified invoice is allowed up to
 * 4 000 kr including VAT, for a credit note and for a company under the
 * small-business exemption (26 §), but never for an EU supply of goods or a
 * supply where the buyer in another EU country is liable (27 §). Read
 * 2026-11-02; AUD-FAKT-7, #55.
 */

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Company\VatExemption;
use App\Domain\Company\VatPeriod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_number' => 'SE556334368901'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->noAddress = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Adresslösa AB', 'org_number' => '5560160680', 'payment_terms_days' => 30]);
});

function addressDraft(Customer $customer, string $price, int $rate = 2500): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => $price, 'vat_rate' => $rate]],
    ]);
}

it('refuses a full invoice to a business without its address', function () {
    // 3 201 + 25 % = 4 001,25, rounded to 4 001 kr: over 4 000 kr.
    expect(fn () => app(IssueInvoice::class)($this->owner, addressDraft($this->noAddress, '3 201')))
        ->toThrow(ValidationException::class, 'saknar fullständig adress');
});

it('allows a simplified invoice of at most 4 000 kr including VAT (26 § p. 1)', function () {
    // 3 200 + 25 % = 4 000 kr exactly.
    $invoice = app(IssueInvoice::class)($this->owner, addressDraft($this->noAddress, '3 200'));

    expect($invoice->total_ore->ore)->toBe(400_000)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued);
});

it('issues the invoice once the address is complete', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Adresslösa AB', 'org_number' => '5560160680', 'payment_terms_days' => 30,
        'address_line1' => 'Storgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm',
    ], $this->noAddress);

    expect(app(IssueInvoice::class)($this->owner, addressDraft($customer, '50 000'))->status)->toBe(InvoiceStatus::Issued);
});

it('requires the address for an EU supply of goods whatever the amount (27 §)', function () {
    $german = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Abnehmer GmbH', 'vat_number' => 'DE123456789', 'country' => 'DE', 'payment_terms_days' => 30, 'foreign_sale' => 'EU_GOODS',
    ]);

    expect(fn () => app(IssueInvoice::class)($this->owner, addressDraft($german, '100')))->toThrow(ValidationException::class, 'saknar fullständig adress');
});

it('does not require the address of a private person outside construction (17 kap. 10 §)', function () {
    $person = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Anna Andersson', 'personal_number' => '19811218-9876', 'payment_terms_days' => 30]);

    expect(app(IssueInvoice::class)($this->owner, addressDraft($person, '10 000'))->status)->toBe(InvoiceStatus::Issued);
});

it('allows a credit note without the address (26 § p. 3)', function () {
    $invoice = app(IssueInvoice::class)($this->owner, addressDraft($this->noAddress, '2 000'));
    $this->noAddress->forceFill(['address_line1' => null])->save();

    $credit = app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));

    expect($credit->isCredit())->toBeTrue();
});

it('lets a company under the small-business exemption use simplified invoices (26 § p. 4)', function () {
    $this->company->forceFill(['vat_registered' => false, 'vat_period' => VatPeriod::None, 'vat_number' => null, 'vat_exemption' => VatExemption::SmallBusiness])->save();

    expect(app(IssueInvoice::class)($this->owner, addressDraft($this->noAddress, '10 000', 0))->status)->toBe(InvoiceStatus::Issued);
});
