<?php

declare(strict_types=1);

/*
 * A row sold in Sweden at 0 % VAT refers to the exemption, and the invoice
 * shows the base per exemption (Mervärdesskattelag (2023:200) 17 kap. 24 §
 * p. 9 and 13, read 2026-11-02; AUD-FAKT-6, #54).
 */

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\VatExemptionReason;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Hyresgästen AB', 'org_number' => '5563343689',
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 30,
    ]);
});

/** @param  list<array<string, mixed>>  $rows */
function exemptDraft(Customer $customer, array $rows): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, ['customer_id' => $customer->id, 'invoice_date' => '2026-03-02', 'rows' => $rows]);
}

it('prints the provision for a domestic row at 0 % with its base, beside the VAT of the other rows', function () {
    // Rent 10 000 kr exempt under 10 kap. 35 §, cleaning 1 000 kr at 25 %.
    $invoice = app(IssueInvoice::class)($this->owner, exemptDraft($this->customer, [
        ['description' => 'Hyra lokal mars', 'quantity' => '1', 'price' => '10 000', 'vat_rate' => 0, 'vat_exemption' => 'REAL_ESTATE'],
        ['description' => 'Städning', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500],
    ]));

    $html = InvoicePdf::html($invoice);

    expect($invoice->rows[0]->vat_exemption)->toBe('REAL_ESTATE')
        ->and($invoice->rows[1]->vat_exemption)->toBeNull()
        ->and($html)->toContain('Undantagen från skatteplikt enligt 10 kap. 35 § mervärdesskattelagen (2023:200).')
        ->and($html)->toContain('Underlag '.Money::ofOre(1_000_000)->format(withCurrency: false).'.');
});

it('states the exemption in general terms when no reason is chosen (24 § p. 13 c)', function () {
    $invoice = app(IssueInvoice::class)($this->owner, exemptDraft($this->customer, [
        ['description' => 'Uthyrning av lokal', 'quantity' => '1', 'price' => '10 000', 'vat_rate' => 0],
    ]));

    expect($invoice->rows[0]->vat_exemption)->toBe(VatExemptionReason::Other->value)
        ->and(InvoicePdf::html($invoice))->toContain('Undantagen från skatteplikt för mervärdesskatt.');
});

it('can say that a row is outside the scope of VAT', function () {
    $invoice = app(IssueInvoice::class)($this->owner, exemptDraft($this->customer, [
        ['description' => 'Utlägg parkering', 'quantity' => '1', 'price' => '120', 'vat_rate' => 0, 'vat_exemption' => 'OUTSIDE_SCOPE'],
    ]));

    expect(InvoicePdf::html($invoice))->toContain('Utanför mervärdesskattens tillämpningsområde');
});

it('gives no exemption to rows of a sale abroad, which carry their own text', function () {
    $german = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Abnehmer GmbH', 'vat_number' => 'DE123456789', 'country' => 'DE',
        'address_line1' => 'Hauptstraße 1', 'postal_code' => '10115', 'city' => 'Berlin', 'payment_terms_days' => 30, 'foreign_sale' => 'EU_GOODS',
    ]);
    $this->company->forceFill(['vat_number' => 'SE556334368901'])->save();

    $invoice = app(IssueInvoice::class)($this->owner, exemptDraft($german, [['description' => 'Maskindel', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]));

    expect($invoice->rows[0]->vat_exemption)->toBeNull();
});

it('keeps the reason on a credit invoice', function () {
    $invoice = app(IssueInvoice::class)($this->owner, exemptDraft($this->customer, [
        ['description' => 'Kursavgift', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 0, 'vat_exemption' => 'EDUCATION'],
    ]));

    $credit = app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));

    expect($credit->rows[0]->vat_exemption)->toBe('EDUCATION')
        ->and(InvoicePdf::html($credit))->toContain('10 kap. 15–18 §§');
});

it('saves the reason chosen in the invoice editor', function () {
    Livewire::test(InvoiceEditor::class)
        ->assertSee('Fastighet, uthyrning av lokal eller bostad (10 kap. 35 §)')
        ->set('customerId', (string) $this->customer->id)
        ->set('rows', [['article' => '', 'description' => 'Hyra', 'quantity' => '1', 'unit' => 'st', 'price' => '5000', 'discount' => '', 'vat_rate' => '0', 'account' => '', 'vat_exemption' => 'REAL_ESTATE']])
        ->call('save')
        ->assertHasNoErrors();

    expect(Invoice::query()->with('rows')->firstOrFail()->rows[0]->vat_exemption)->toBe('REAL_ESTATE');
});
