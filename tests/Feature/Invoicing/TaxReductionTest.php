<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterTaxReductionPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\TaxReduction;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Invoice;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->household = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Anna Andersson', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30, 'address_line1' => 'Testgatan 1', 'postal_code' => '111 22', 'city' => 'Stockholm',
    ]);
});

/** Labour 500 kr an hour and material, both at 25 % VAT. */
function rotRenovation(string $type = 'ROT', int $hours = 10, ?string $property = 'Uppsala Kåbo 1:23'): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => test()->household->id,
        'invoice_date' => '2026-03-02',
        'tax_reduction' => $type,
        'property_designation' => $property,
        'rows' => [
            ['description' => 'Badrumsrenovering, arbete', 'quantity' => (string) $hours, 'price' => '500', 'vat_rate' => 2500, 'labour' => true],
            ['description' => 'Kakel', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 2500, 'labour' => false],
        ],
    ]);
}

function taxReductionLines(?string $voucherId): array
{
    return Voucher::query()->with('rows.account')->findOrFail($voucherId)->rows
        ->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->all();
}

it('takes 30 % of the labour including VAT off a ROT invoice and books it on 1513', function () {
    $draft = rotRenovation();

    expect($draft->tax_reduction_ore->ore)->toBe(187_500)
        ->and($draft->total_ore->ore)->toBe(687_500)
        ->and($draft->rows->pluck('is_labour')->all())->toBe([true, false]);

    $issued = app(IssueInvoice::class)($this->owner, $draft);

    expect(taxReductionLines($issued->voucher_id))->toMatchArray([1510 => 687_500, 1513 => 187_500, 2611 => -175_000]);

    $paid = app(RegisterTaxReductionPayment::class)($this->owner, $issued, CarbonImmutable::parse('2026-04-10'), Money::parse('1 875'));

    expect($paid->tax_reduction_paid_ore->ore)->toBe(187_500)
        ->and(fn () => app(RegisterTaxReductionPayment::class)($this->owner, $paid, CarbonImmutable::parse('2026-04-11'), Money::parse('1')))->toThrow(AuthorizationException::class);
});

it('takes 50 % for RUT and keeps within the yearly ceiling on the company\'s invoices', function () {
    expect(rotRenovation('RUT', property: null)->tax_reduction_ore->ore)->toBe(312_500);

    app(IssueInvoice::class)($this->owner, rotRenovation('ROT', 260));
    $second = rotRenovation();

    // 260 hours gave 48 750 kr; 1 250 kr of the 50 000 kr ROT ceiling is left.
    expect($second->tax_reduction_ore->ore)->toBe(125_000)
        ->and(TaxReduction::amount(625_000, 3000, 10_000_000))->toBe(187_500)
        ->and(TaxReduction::amount(-625_050, 3000, 0))->toBe(-187_500);
});

it('needs a private person, a property for ROT, labour rows and fakturametoden', function () {
    $this->household->forceFill(['personal_number' => null])->save();
    expect(fn () => rotRenovation())->toThrow(ValidationException::class);

    $this->household->forceFill(['personal_number' => '19121212-1212'])->save();
    expect(fn () => app(IssueInvoice::class)($this->owner, rotRenovation(property: null)))->toThrow(ValidationException::class);

    $this->company->forceFill(['accounting_method' => 'KONTANTMETODEN'])->save();
    expect(fn () => app(IssueInvoice::class)($this->owner, rotRenovation()))->toThrow(ValidationException::class);
});

it('credits the deduction too, and the editor carries the choice', function () {
    $issued = app(IssueInvoice::class)($this->owner, rotRenovation());
    $credit = app(CreditInvoice::class)($this->owner, $issued);

    expect($credit->tax_reduction)->toBe('ROT')
        ->and($credit->tax_reduction_ore->ore)->toBe(-187_500)
        ->and($credit->total_ore->ore)->toBe(-687_500);

    Livewire::test(InvoiceEditor::class, ['invoice' => rotRenovation()])
        ->assertSet('taxReduction', 'ROT')
        ->assertSet('propertyDesignation', 'Uppsala Kåbo 1:23')
        ->assertSet('rows.0.labour', '1')
        ->assertSee('Arbete');
});
