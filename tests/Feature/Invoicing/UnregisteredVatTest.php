<?php

declare(strict_types=1);

/*
 * A company that is not registered for VAT (AUD-FAKT-5, #51). Mervärdesskattelag
 * (2023:200), read 2026-11-02: 16 kap. 23 § (VAT charged wrongly on an invoice
 * must be paid to the state), 17 kap. 24 § p. 13 and 28 § p. 6 (the invoice
 * refers to the exemption), 18 kap. 4 § (the small-business exemption).
 */

use App\Actions\Company\UpdateCompanyDetails;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Company\VatExemption;
use App\Domain\Company\VatPeriod;
use App\Domain\Invoicing\InvoicePdf;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Vat\VatReturns;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_registered' => false, 'vat_period' => VatPeriod::None, 'vat_number' => null, 'vat_exemption' => VatExemption::SmallBusiness])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Kundbolaget AB', 'org_number' => '5563343689',
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 30,
    ]);
});

/** @param  array<string, mixed>  $extra */
function unregDraft(Customer $customer, int $rate, array $extra = []): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => $rate]],
        ...$extra,
    ]);
}

function unregOutputVat(): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('accounts.number', 2611)
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

it('refuses VAT on an invoice from a company that is not registered, unless confirmed', function () {
    expect(fn () => app(IssueInvoice::class)($this->owner, unregDraft($this->customer, 2500)))
        ->toThrow(ValidationException::class, 'inte momsregistrerat');
});

it('issues an invoice with VAT when confirmed, books it on 2611 and shows that it must be paid in', function () {
    // 1 000 kr + 25 % = 250 kr VAT, which must be paid to the state (16 kap. 23 §).
    $invoice = app(IssueInvoice::class)($this->owner, unregDraft($this->customer, 2500, ['unregistered_vat_confirmed' => true]));

    expect($invoice->unregistered_vat_confirmed)->toBeTrue()
        ->and($invoice->vat_exemption_text)->toBeNull()
        ->and(unregOutputVat())->toBe(-25_000);

    Livewire::test(VatReturns::class)
        ->assertSee('Det finns utgående moms på')
        ->assertSee(Money::ofOre(25_000)->format())
        ->assertSee('16 kap. 23 §');
});

it('prints the small-business exemption on an invoice without VAT (17 kap. 28 § p. 6)', function () {
    $invoice = app(IssueInvoice::class)($this->owner, unregDraft($this->customer, 0));

    expect($invoice->vat_exemption_text)->toBe('Undantagen från skatteplikt enligt 18 kap. 4 § mervärdesskattelagen (2023:200).')
        ->and(InvoicePdf::html($invoice))->toContain('18 kap. 4 § mervärdesskattelagen');
});

it('keeps the exemption wording the invoice was issued with', function () {
    $invoice = app(IssueInvoice::class)($this->owner, unregDraft($this->customer, 0));
    $this->company->forceFill(['vat_exemption' => VatExemption::ExemptSupplies])->save();

    expect(InvoicePdf::html($invoice->fresh()))->toContain('18 kap. 4 §');
});

it('prints no exemption when the registration is pending', function () {
    $this->company->forceFill(['vat_exemption' => VatExemption::RegistrationPending])->save();

    $invoice = app(IssueInvoice::class)($this->owner, unregDraft($this->customer, 0));

    expect($invoice->vat_exemption_text)->toBeNull();
});

it('lets a registered company charge VAT as before and prints no exemption', function () {
    $this->company->forceFill(['vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'vat_exemption' => null])->save();

    $invoice = app(IssueInvoice::class)($this->owner, unregDraft($this->customer, 2500));

    expect($invoice->vat_exemption_text)->toBeNull()
        ->and(unregOutputVat())->toBe(-25_000);
});

it('keeps the reason in the company settings only while the company is not registered', function () {
    $input = [
        'name' => 'Granskningsbolaget AB', 'address_line1' => 'Storgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm',
        'email' => null, 'phone' => null, 'website' => null, 'bankgiro' => null, 'plusgiro' => null,
        'vat_period' => 'NONE', 'vat_exemption' => 'EXEMPT_SUPPLIES',
        'fiscal_year_start_month' => 1, 'accounting_method' => 'FAKTURAMETODEN', 'reporting_framework' => $this->company->reporting_framework->value,
        'f_skatt' => true, 'employer_registered' => false,
    ];

    app(UpdateCompanyDetails::class)($this->owner, $this->company, $input);
    expect($this->company->refresh()->vat_exemption)->toBe(VatExemption::ExemptSupplies);

    app(UpdateCompanyDetails::class)($this->owner, $this->company, [...$input, 'vat_period' => 'QUARTERLY']);
    expect($this->company->refresh()->vat_exemption)->toBeNull();
});

it('asks for the confirmation in the invoice editor', function () {
    Livewire::test(InvoiceEditor::class)
        ->assertSee('Företaget är inte momsregistrerat')
        ->set('customerId', (string) $this->customer->id)
        ->set('rows', [['article' => '', 'description' => 'Konsulttimme', 'quantity' => '1', 'unit' => 'st', 'price' => '1000', 'discount' => '', 'vat_rate' => '2500', 'account' => '']])
        ->call('issue')
        ->assertHasErrors('invoice')
        ->set('unregisteredVatConfirmed', true)
        ->call('issue')
        ->assertHasNoErrors();

    expect(Invoice::query()->whereNotNull('number')->count())->toBe(1);
});
