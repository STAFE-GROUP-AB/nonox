<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Vat\VatReturn;
use App\Livewire\Invoicing\Customers;
use App\Livewire\Invoicing\InvoiceEditor;
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
    $this->builder = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Byggfirman AB', 'org_number' => '5563343689', 'vat_number' => 'SE556334368901',
        'payment_terms_days' => 30, 'reverse_charge_construction' => true,
    ]);
});

function constructionDraft(?bool $reverseCharge = null): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, array_filter([
        'customer_id' => test()->builder->id,
        'invoice_date' => '2026-03-02',
        'reverse_charge' => $reverseCharge,
        'rows' => [
            ['description' => 'Snickeriarbete', 'quantity' => '10', 'price' => '650', 'vat_rate' => 2500],
            ['description' => 'Material', 'quantity' => '1', 'price' => '3 500', 'vat_rate' => 2500],
        ],
    ], fn ($value) => $value !== null));
}

it('invoices a construction customer without VAT and books the sale on 3231', function () {
    $draft = constructionDraft();

    expect($draft->reverse_charge)->toBeTrue()
        ->and($draft->vat_ore->ore)->toBe(0)
        ->and($draft->total_ore->ore)->toBe(1_000_000)
        ->and($draft->rows->pluck('vat_rate')->all())->toBe([0, 0]);

    $issued = app(IssueInvoice::class)($this->owner, $draft);
    $lines = Voucher::query()->with('rows.account')->findOrFail($issued->voucher_id)->rows
        ->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    expect($lines)->toEqualCanonicalizing([1510 => 1_000_000, 3231 => -1_000_000]);

    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $boxes = VatReturn::calculate($year, CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    expect($boxes[41])->toBe(10_000)
        ->and($boxes[5] ?? 0)->toBe(0)
        ->and($boxes[10] ?? 0)->toBe(0);
});

it('can be switched off per invoice, and a credit keeps the treatment', function () {
    $normal = constructionDraft(false);

    expect($normal->reverse_charge)->toBeFalse()
        ->and($normal->vat_ore->ore)->toBe(250_000);

    $issued = app(IssueInvoice::class)($this->owner, constructionDraft());
    $credit = app(CreditInvoice::class)($this->owner, $issued);

    expect($credit->reverse_charge)->toBeTrue()
        ->and($credit->vat_ore->ore)->toBe(0);
});

it('needs both VAT numbers and prints the reverse charge text', function () {
    $this->builder->forceFill(['vat_number' => null])->save();

    expect(fn () => app(IssueInvoice::class)($this->owner, constructionDraft()))->toThrow(ValidationException::class);

    $this->builder->forceFill(['vat_number' => 'SE556334368901'])->save();
    $issued = app(IssueInvoice::class)($this->owner, constructionDraft());

    expect(view('invoicing.pdf', ['invoice' => $issued, 'company' => $this->company->fresh(), 'brand' => Brand::for($this->company), 'totals' => ['net' => 1_000_000, 'vat' => 0, 'vat_by_rate' => [], 'rounding' => 0, 'total' => 1_000_000]])->render())
        ->toContain('Omvänd betalningsskyldighet för byggtjänster.')
        ->and(str_starts_with(InvoicePdf::render($issued), '%PDF'))->toBeTrue();
});

it('is set on the customer and follows the customer in the editor', function () {
    Livewire::test(Customers::class)
        ->call('edit', $this->builder->id)
        ->assertSet('reverseCharge', true)
        ->set('reverseCharge', false)
        ->call('save');

    expect($this->builder->fresh()->reverse_charge_construction)->toBeFalse();

    $this->builder->fresh()->forceFill(['reverse_charge_construction' => true])->save();

    Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $this->builder->id)
        ->assertSet('reverseCharge', true);
});
