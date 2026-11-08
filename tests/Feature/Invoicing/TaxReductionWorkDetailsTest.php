<?php

declare(strict_types=1);

/*
 * ADR-074 §5 and O5/O6 on the invoice itself: a ROT or RUT invoice records
 * the hours worked and the kind of work (lag (2009:194) 9 § p. 4, AO-R14),
 * both part of the issued invoice's immutable content; several buyers can
 * share the deduction, each within the own ceiling (IL 67 kap. 19 §); and
 * the PDF prints the buyer's personnummer until L6 is answered.
 */

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\TaxReduction;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Invoice;
use App\Models\InvoiceTaxReductionBuyer;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $save = app(SaveCustomer::class);
    $this->anna = $save($this->owner, $this->company, ['name' => 'Anna Exempel', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30, 'address_line1' => 'Exempelvägen 1', 'postal_code' => '111 22', 'city' => 'Exempelstad']);
    $this->bo = $save($this->owner, $this->company, ['name' => 'Bo Exempel', 'personal_number' => '19101010-1010', 'payment_terms_days' => 30, 'address_line1' => 'Exempelvägen 1', 'postal_code' => '111 22', 'city' => 'Exempelstad']);
});

/** @param  array<string, mixed>  $extra */
function rotWorkDraft(array $extra = [], string $price = '2 400'): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => test()->anna->id, 'invoice_date' => '2026-03-02', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'rows' => [
            ['description' => 'Byte av golvbrunn, arbete', 'quantity' => '1', 'price' => $price, 'vat_rate' => 2500, 'labour' => true],
            ['description' => 'Golvbrunn', 'quantity' => '1', 'price' => '1 200', 'vat_rate' => 2500],
        ],
        ...$extra,
    ]);
}

it('records the hours and the kind of work, typed or in milli-hours, and keeps them when the form does not name them', function () {
    $draft = rotWorkDraft(['labour_hours' => '1,5', 'work_description' => 'Byte av golvbrunn']);

    expect($draft->labour_hours_milli)->toBe(1500)
        ->and($draft->labourHoursLabel())->toBe('1,5')
        ->and($draft->work_description)->toBe('Byte av golvbrunn');

    $again = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->anna->id, 'invoice_date' => '2026-03-02', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '2 400', 'vat_rate' => 2500, 'labour' => true]],
    ], $draft);

    expect($again->labour_hours_milli)->toBe(1500)->and($again->work_description)->toBe('Byte av golvbrunn')
        ->and(rotWorkDraft(['labour_hours_milli' => 4000])->labourHoursLabel())->toBe('4');
});

it('refuses hours it cannot read and records none without a tax reduction', function () {
    expect(fn () => rotWorkDraft(['labour_hours' => 'fyra']))->toThrow(ValidationException::class);

    $plain = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->anna->id, 'invoice_date' => '2026-03-02', 'labour_hours' => '4', 'work_description' => 'Målning',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]],
    ]);

    expect($plain->labour_hours_milli)->toBeNull()->and($plain->work_description)->toBeNull();
});

it('keeps the hours and the kind of work unchanged once the invoice is issued', function () {
    $invoice = app(IssueInvoice::class)($this->owner, rotWorkDraft(['labour_hours' => '4', 'work_description' => 'Byte av golvbrunn']));

    expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['labour_hours_milli' => 9000])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['work_description' => 'Annat'])))->toThrow(QueryException::class);
});

it('prints the hours, the kind of work and the buyer\'s personnummer on the PDF (L6, until answered)', function () {
    $html = InvoicePdf::html(app(IssueInvoice::class)($this->owner, rotWorkDraft(['labour_hours' => '4', 'work_description' => 'Byte av golvbrunn'])));

    expect($html)->toContain('Arbetade timmar: 4')
        ->toContain('Utfört arbete: Byte av golvbrunn')
        ->toContain('19121212-1212');
});

it('shows the hours and the kind of work in the invoice form', function () {
    $draft = rotWorkDraft(['labour_hours' => '4', 'work_description' => 'Byte av golvbrunn']);

    Livewire::test(InvoiceEditor::class, ['invoice' => $draft])
        ->assertSet('labourHours', '4')
        ->assertSet('workDescription', 'Byte av golvbrunn')
        ->set('labourHours', '6')
        ->call('save');

    expect($draft->fresh()->labour_hours_milli)->toBe(6000);
});

it('splits the deduction between two buyers, each within the own ceiling (O6)', function () {
    // Labour 40 000 + 25 % = 50 000 kr; 30 % = 15 000 kr, half each.
    $draft = rotWorkDraft(['tax_reduction_buyers' => [
        ['customer_id' => $this->anna->id, 'share_bp' => 5000],
        ['customer_id' => $this->bo->id, 'share_bp' => 5000],
    ]], '40 000');

    expect($draft->tax_reduction_ore->ore)->toBe(1_500_000)
        ->and($draft->taxReductionBuyers->map(fn (InvoiceTaxReductionBuyer $buyer): int => $buyer->deduction_ore->ore)->all())->toBe([750_000, 750_000]);

    $issued = app(IssueInvoice::class)($this->owner, $draft);

    // Bo has used 7 500 kr of his 50 000 kr ROT this year; Anna as well.
    expect(TaxReduction::used($this->bo, 2026))->toBe(['ROT' => 750_000])
        ->and(TaxReduction::used($this->anna, 2026))->toBe(['ROT' => 750_000])
        ->and(InvoicePdf::html($issued))->toContain('19101010-1010')->toContain('Bo Exempel');
});

it('takes each buyer\'s own room: a buyer whose ceiling is used gets nothing (O6)', function () {
    // Bo alone uses his whole 50 000 kr: labour 133 334 + 25 % → 30 % = 50 000 kr.
    app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->bo->id, 'invoice_date' => '2026-03-02', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '133 334', 'vat_rate' => 2500, 'labour' => true]],
    ]));

    $split = rotWorkDraft(['tax_reduction_buyers' => [
        ['customer_id' => $this->anna->id, 'share_bp' => 5000],
        ['customer_id' => $this->bo->id, 'share_bp' => 5000],
    ]], '40 000');

    expect($split->taxReductionBuyers->map(fn (InvoiceTaxReductionBuyer $buyer): int => $buyer->deduction_ore->ore)->all())->toBe([750_000, 0])
        ->and($split->tax_reduction_ore->ore)->toBe(750_000);
});

it('refuses shares that do not add up to 100 %, a buyer without personnummer, or buyers without the invoice customer', function (array $buyers, string $message) {
    $company = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Exempel AB', 'payment_terms_days' => 30, 'address_line1' => 'Exempelvägen 2', 'postal_code' => '111 22', 'city' => 'Exempelstad']);
    $resolved = array_map(fn (array $buyer): array => ['customer_id' => match ($buyer[0]) {
        'anna' => $this->anna->id, 'bo' => $this->bo->id, default => $company->id,
    }, 'share_bp' => $buyer[1]], $buyers);

    try {
        rotWorkDraft(['tax_reduction_buyers' => $resolved]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->toContain($message);
    }
})->with([
    'shares' => [[['anna', 5000], ['bo', 4000]], '100 procent'],
    'no personnummer' => [[['anna', 5000], ['company', 5000]], 'personnummer'],
    'without the customer' => [[['bo', 10000]], 'en av köparna'],
]);

it('splits a credit invoice\'s deduction between the buyers of the invoice it credits', function () {
    $issued = app(IssueInvoice::class)($this->owner, rotWorkDraft(['tax_reduction_buyers' => [
        ['customer_id' => $this->anna->id, 'share_bp' => 5000],
        ['customer_id' => $this->bo->id, 'share_bp' => 5000],
    ]], '40 000'));

    $credit = app(CreditInvoice::class)($this->owner, $issued);

    expect($credit->tax_reduction_ore->ore)->toBe(-1_500_000)
        ->and($credit->taxReductionBuyers()->get()->map(fn (InvoiceTaxReductionBuyer $buyer): int => $buyer->deduction_ore->ore)->all())->toBe([-750_000, -750_000])
        ->and($credit->labour_hours_milli)->toBeNull();
});

it('reverses each buyer\'s own deduction on a credit, not the shares: a buyer who got nothing gets nothing back (review P1-1)', function () {
    // Bo has used his whole 50 000 kr: the split invoice stores [7 500, 0].
    app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->bo->id, 'invoice_date' => '2026-03-02', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '133 334', 'vat_rate' => 2500, 'labour' => true]],
    ]));
    $issued = app(IssueInvoice::class)($this->owner, rotWorkDraft(['tax_reduction_buyers' => [
        ['customer_id' => $this->anna->id, 'share_bp' => 5000],
        ['customer_id' => $this->bo->id, 'share_bp' => 5000],
    ]], '40 000'));
    $deductions = fn (Invoice $invoice): array => $invoice->taxReductionBuyers()->get()->map(fn (InvoiceTaxReductionBuyer $buyer): int => $buyer->deduction_ore->ore)->all();

    expect($deductions($issued))->toBe([750_000, 0]);

    // Half the labour credited first, then the rest.
    $half = app(CreditInvoice::class)($this->owner, $issued);
    $half = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->anna->id, 'invoice_date' => '2026-03-02', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '20 000', 'vat_rate' => 2500, 'labour' => true]],
    ], $half);
    app(IssueInvoice::class)($this->owner, $half);

    expect($half->fresh()->tax_reduction_ore->ore)->toBe(-375_000)
        ->and($deductions($half->fresh()))->toBe([-375_000, 0]);

    $rest = app(CreditInvoice::class)($this->owner, $issued->fresh());
    $rest = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->anna->id, 'invoice_date' => '2026-03-02', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '20 000', 'vat_rate' => 2500, 'labour' => true]],
    ], $rest);

    expect($rest->tax_reduction_ore->ore)->toBe(-375_000)
        ->and($deductions($rest))->toBe([-375_000, 0]);
});

it('never adds the personnummer when an invoice issued before this release is rendered again (review P3-8)', function () {
    $invoice = app(IssueInvoice::class)($this->owner, rotWorkDraft(['labour_hours' => '4']));

    expect($invoice->prints_buyer_personal_number)->toBeTrue()
        ->and(InvoicePdf::html($invoice))->toContain('19121212-1212');

    // An invoice issued earlier keeps the flag off, as the migration leaves every existing row; it cannot be changed afterwards.
    DB::statement('ALTER TABLE invoices DISABLE TRIGGER invoices_protect_issued');
    DB::table('invoices')->where('id', $invoice->id)->update(['prints_buyer_personal_number' => false]);
    DB::statement('ALTER TABLE invoices ENABLE TRIGGER invoices_protect_issued');

    expect(InvoicePdf::html($invoice->fresh()))->not->toContain('19121212-1212')
        ->and(fn () => DB::transaction(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['prints_buyer_personal_number' => true])))->toThrow(QueryException::class);
});
