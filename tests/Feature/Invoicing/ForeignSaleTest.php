<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Vat\VatReturn;
use App\Livewire\Invoicing\Customers;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Support\Branding\Brand;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_number' => 'SE556334368901'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->german = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Muster GmbH', 'vat_number' => 'DE123456789', 'country' => 'de', 'address_line1' => 'Testgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm',
        'payment_terms_days' => 30, 'foreign_sale' => ForeignSale::EuServices->value,
    ]);
    $this->american = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Example Inc', 'country' => 'US', 'payment_terms_days' => 30,
    ]);
});

function foreignDraft(Customer $customer, ?string $sale = null, bool $setSale = false): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        ...($setSale ? ['foreign_sale' => $sale] : []),
        'rows' => [['description' => 'Konsultarbete', 'quantity' => '10', 'price' => '1 000', 'vat_rate' => 2500]],
    ]);
}

/** @return array<int, int> */
function foreignLines(Invoice $invoice): array
{
    return Voucher::query()->with('rows.account')->findOrFail($invoice->voucher_id)->rows
        ->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();
}

/** @return array<int, int> */
function foreignBoxes(): array
{
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    return VatReturn::calculate($year, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'))->boxes();
}

it('stores the country as an upper-case code', function () {
    expect($this->german->country)->toBe('DE')
        ->and($this->german->foreign_sale)->toBe(ForeignSale::EuServices);
});

it('invoices services to an EU business without VAT, on 3308 and in box 39', function () {
    $draft = foreignDraft($this->german);

    expect($draft->foreign_sale)->toBe(ForeignSale::EuServices)
        ->and($draft->vat_ore->ore)->toBe(0)
        ->and($draft->total_ore->ore)->toBe(1_000_000);

    $issued = app(IssueInvoice::class)($this->owner, $draft);

    expect(foreignLines($issued))->toEqualCanonicalizing([1510 => 1_000_000, 3308 => -1_000_000])
        ->and(foreignBoxes()[39])->toBe(10_000)
        ->and(foreignBoxes()[10] ?? 0)->toBe(0);
});

it('books goods to the EU on 3108 in box 35 and exports on 3105 in box 36', function () {
    app(IssueInvoice::class)($this->owner, foreignDraft($this->german, ForeignSale::EuGoods->value, true));
    $export = app(IssueInvoice::class)($this->owner, foreignDraft($this->american, ForeignSale::ExportGoods->value, true));

    expect(foreignLines($export))->toEqualCanonicalizing([1510 => 1_000_000, 3105 => -1_000_000])
        ->and(foreignBoxes()[35])->toBe(10_000)
        ->and(foreignBoxes()[36])->toBe(10_000);
});

it('books services outside the EU on 3305 in box 40', function () {
    app(IssueInvoice::class)($this->owner, foreignDraft($this->american, ForeignSale::ExportServices->value, true));

    expect(foreignBoxes()[40])->toBe(10_000);
});

it('refuses an EU sale without the buyer\'s VAT number from that country', function () {
    $this->german->forceFill(['vat_number' => 'SE556334368901'])->save();

    expect(fn () => app(IssueInvoice::class)($this->owner, foreignDraft($this->german->fresh())))->toThrow(ValidationException::class);
});

it('refuses an EU sale to a customer outside the EU, and an export to an EU customer', function () {
    expect(fn () => app(IssueInvoice::class)($this->owner, foreignDraft($this->american, ForeignSale::EuServices->value, true)))->toThrow(ValidationException::class)
        ->and(fn () => app(IssueInvoice::class)($this->owner, foreignDraft($this->german, ForeignSale::ExportGoods->value, true)))->toThrow(ValidationException::class);
});

it('can be switched off per invoice, and a credit keeps the treatment', function () {
    expect(foreignDraft($this->german, null, true)->vat_ore->ore)->toBe(250_000);

    $credit = app(CreditInvoice::class)($this->owner, app(IssueInvoice::class)($this->owner, foreignDraft($this->german)));

    expect($credit->foreign_sale)->toBe(ForeignSale::EuServices)
        ->and($credit->vat_ore->ore)->toBe(0);
});

it('cannot be combined with reverse charge in construction or ROT', function () {
    expect(fn () => app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->german->id, 'invoice_date' => '2026-03-02', 'reverse_charge' => true,
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]],
    ]))->toThrow(ValidationException::class);
});

it('prints the exemption text and both VAT numbers', function () {
    $issued = app(IssueInvoice::class)($this->owner, foreignDraft($this->german));

    expect(view('invoicing.pdf', ['invoice' => $issued, 'company' => $this->company->fresh(), 'brand' => Brand::for($this->company), 'totals' => ['net' => 1_000_000, 'vat' => 0, 'vat_by_rate' => [], 'rounding' => 0, 'total' => 1_000_000]])->render())
        ->toContain('Omvänd betalningsskyldighet. / Reverse charge.')
        ->toContain('DE123456789')
        ->toContain('SE556334368901');
});

it('is set on the customer and follows the customer in the editor', function () {
    Livewire::test(Customers::class)
        ->call('edit', $this->american->id)
        ->assertSet('form.country', 'US')
        ->set('foreignSale', ForeignSale::ExportServices->value)
        ->call('save');

    expect($this->american->fresh()->foreign_sale)->toBe(ForeignSale::ExportServices);

    Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $this->german->id)
        ->assertSet('foreignSale', ForeignSale::EuServices->value);
});
