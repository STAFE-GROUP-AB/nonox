<?php

declare(strict_types=1);

/*
 * ADR-074 §5 with the rule sheet's fixtures F1, F3, F4, F5, F6, F10: the
 * invoice basis is a draft built through SaveDraftInvoice; ROT/RUT only on
 * labour kinds (AO-R13), with hours and the kind of work on the invoice
 * (AO-R14); reverse charge inherited (AO-R20); ÄTA that are not approved
 * held back; the 15 % warning on an approximate price (AO-R6); the
 * construction invoice deadline (AO-R22). All data synthetic.
 */

use App\Actions\Invoicing\IssueInvoice;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\TaxReduction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\DecideChangeOrder;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\RecordTime;
use App\Modules\WorkOrders\Actions\ReverseEntry;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Domain\Advice;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Support\SpecificationPdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    WorkOrders::enable($this->company, $this->owner);
});

function basis(WorkOrder $workOrder, ?string $upTo = null): Invoice
{
    return app(CreateInvoiceBasis::class)(test()->owner, test()->company, $workOrder, $upTo === null ? null : CarbonImmutable::parse($upTo));
}

/** @param  array<string, mixed>  $input */
function approvedChange(WorkOrder $workOrder, array $input): ChangeOrder
{
    $changeOrder = app(SaveChangeOrder::class)(test()->owner, test()->company, $workOrder, ['basis' => 'CHANGE_ORDERED', 'price_form' => 'FIXED', ...$input]);

    return app(DecideChangeOrder::class)->approveOrally(test()->owner, test()->company, $changeOrder, ['name' => 'Kunden', 'channel' => 'PHONE']);
}

it('F1: invoices an approved fixed-price ÄTA under ROT with its labour, hours and kind of work', function () {
    $customer = WorkOrders::privateCustomer($this->company, $this->owner);
    // Bathroom at 80 000 kr incl. VAT (64 000 ex), of which labour 50 000 kr incl. VAT (40 000 ex).
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $customer, [
        'price_form' => 'FIXED', 'contract_sum' => '64 000', 'contract_labour' => '40 000',
        'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
    ]);
    foreach (['2026-02-26', '2026-02-27', '2026-03-02'] as $day) {
        WorkOrders::hours($this->company, $this->owner, $workOrder, '10', $day);
    }
    $main = app(IssueInvoice::class)($this->owner, basis($workOrder));

    expect($main->tax_reduction_ore->ore)->toBe(1_500_000)
        ->and($main->labour_hours_milli)->toBe(30_000);

    $ata = approvedChange($workOrder, ['title' => 'Byte av golvbrunn', 'description' => 'Ny golvbrunn enligt kundens önskemål.', 'labour' => '2 400', 'material' => '1 200']);
    WorkOrders::hours($this->company, $this->owner, $workOrder, '4', changeOrderId: $ata->id);

    $invoice = basis($workOrder);
    $labour = $invoice->rows->where('is_labour', true);

    expect($invoice->isDraft())->toBeTrue()
        ->and($labour)->toHaveCount(1)
        ->and($labour->first()->net_ore->ore)->toBe(240_000)
        ->and($invoice->rows->where('is_labour', false)->first()->net_ore->ore)->toBe(120_000)
        ->and($invoice->tax_reduction_ore->ore)->toBe(90_000)
        ->and($invoice->total_ore->ore)->toBe(360_000)
        ->and($invoice->labour_hours_milli)->toBe(4000)
        ->and($invoice->work_description)->toBe('Byte av golvbrunn')
        ->and($invoice->rows->first()->description)->toStartWith('ÄTA 1: ');

    app(IssueInvoice::class)($this->owner, $invoice);

    expect(TaxReduction::CEILINGS[TaxReduction::ROT] - array_sum(TaxReduction::used($customer, 2026)))->toBe(3_410_000)
        ->and(InvoicePdf::html($invoice->fresh()))->toContain('Arbetade timmar: 4');
});

it('F10: refuses a ROT basis without recorded hours, fixed price included', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner), [
        'price_form' => 'FIXED', 'contract_sum' => '64 000', 'contract_labour' => '40 000', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
    ]);

    expect(fn () => basis($workOrder))->toThrow(ValidationException::class, 'Registrera arbetade timmar. Skatteverket kräver antalet timmar även vid fast pris.');
});

it('marks only labour kinds as ROT labour: travel and material never (AO-R13)', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner), [
        'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'rates' => [WorkOrders::kind()->id => '600', WorkOrders::kind('RESA')->id => '350'],
    ]);
    WorkOrders::hours($this->company, $this->owner, $workOrder, '3', rate: '600');
    app(RecordTime::class)($this->owner, $this->company, $workOrder, ['labour_kind_id' => WorkOrders::kind('RESA')->id, 'work_date' => '2026-03-02', 'quantity' => '2']);
    app(RecordMaterial::class)($this->owner, $this->company, $workOrder, ['source' => 'FREE', 'description' => 'Fogmassa', 'quantity' => '2', 'unit_cost' => '100', 'unit_price' => '150', 'work_date' => '2026-03-02']);

    $invoice = basis($workOrder);

    expect($invoice->rows->map(fn ($row) => [$row->description, $row->is_labour])->all())->toBe([
        ['Arbete', true],
        ['Resa', false],
        ['Fogmassa', false],
    ])->and($invoice->labour_hours_milli)->toBe(3000)
        ->and($invoice->tax_reduction_ore->ore)->toBe(intdiv(intdiv(180_000 * 125 / 100 * 3000, 10_000), 100) * 100);
});

it('F4: one reverse-charge invoice for a subcontractor with an ÄTA, no VAT on any row, and no ROT', function () {
    $builder = WorkOrders::business($this->company, $this->owner, extra: ['reverse_charge_construction' => true]);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $builder, ['rates' => [WorkOrders::kind()->id => '550']]);

    for ($day = 2; $day <= 3; $day++) {
        WorkOrders::hours($this->company, $this->owner, $workOrder, '10', "2026-03-0{$day}", rate: '550');
    }

    approvedChange($workOrder, ['title' => 'Extra vägg', 'description' => 'Extra vägg med material.', 'labour' => '8 000']);
    $this->travelTo(CarbonImmutable::parse('2026-03-04 10:00:00'));

    $invoice = basis($workOrder);

    expect($invoice->reverse_charge)->toBeTrue()
        ->and($invoice->vat_ore->ore)->toBe(0)
        ->and($invoice->rows->every(fn ($row) => $row->vat_rate === 0 && ! $row->is_labour))->toBeTrue()
        ->and($invoice->net_ore->ore)->toBe(1_900_000)
        ->and($invoice->tax_reduction)->toBeNull();
});

it('F5: a housing association pays VAT and gets no ROT', function () {
    $association = WorkOrders::business($this->company, $this->owner, 'Brf Exemplet', '769900456');
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $association);
    WorkOrders::hours($this->company, $this->owner, $workOrder, '2', rate: '500');

    $invoice = basis($workOrder);

    expect($invoice->vat_ore->ore)->toBe(25_000)->and($invoice->tax_reduction)->toBeNull()
        ->and(Advice::isConsumer($association))->toBeFalse();
});

it('holds back time on an ÄTA that is not approved, reserves entries for the draft and releases them when it is deleted', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner));
    $main = WorkOrders::hours($this->company, $this->owner, $workOrder, '2');
    $draftChange = app(SaveChangeOrder::class)($this->owner, $this->company, $workOrder, ['title' => 'Extra eluttag', 'description' => 'Ett uttag till.', 'labour' => '500']);
    $held = WorkOrders::hours($this->company, $this->owner, $workOrder, '1', changeOrderId: $draftChange->id);

    $invoice = basis($workOrder);

    expect($main->fresh()->invoice_id)->toBe($invoice->id)
        ->and($held->fresh()->invoice_id)->toBeNull()
        ->and(fn () => basis($workOrder))->toThrow(ValidationException::class, 'inget att fakturera');

    $invoice->delete();

    expect($main->fresh()->invoice_id)->toBeNull();
});

it('keeps entries on an issued invoice from being reversed', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner));
    $entry = WorkOrders::hours($this->company, $this->owner, $workOrder, '2');
    app(IssueInvoice::class)($this->owner, basis($workOrder));

    expect(fn () => app(ReverseEntry::class)($this->owner, $this->company, $entry->fresh(), 'Fel'))
        ->toThrow(ValidationException::class, 'kreditera');
});

it('F3: warns when main work passes 115 % of an approximate price, with approved ÄTA apart, never blocking', function () {
    $customer = WorkOrders::privateCustomer($this->company, $this->owner);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $customer, ['price_form' => 'ESTIMATE', 'approximate_price' => '40 000']);

    // 36 800 ex VAT = 46 000 incl.: exactly at the limit, no warning yet.
    WorkOrders::hours($this->company, $this->owner, $workOrder, '20', rate: '1840');
    expect(Advice::approximatePrice($workOrder, $customer)['exceeded'])->toBeFalse();

    WorkOrders::hours($this->company, $this->owner, $workOrder, '0,5', rate: '800');
    $ata = approvedChange($workOrder, ['title' => 'Extra', 'description' => 'Extra arbete.', 'price_form' => 'TIME_AND_MATERIAL']);
    WorkOrders::hours($this->company, $this->owner, $workOrder, '5', changeOrderId: $ata->id, rate: '800');

    $warning = Advice::approximatePrice($workOrder, $customer);

    expect($warning['recorded'])->toBe(4_650_000)
        ->and($warning['limit'])->toBe(4_600_000)
        ->and($warning['exceeded'])->toBeTrue()
        ->and($warning['change_orders'])->toBe(500_000)
        ->and(basis($workOrder)->isDraft())->toBeTrue();
});

it('F6: flags uninvoiced construction work from the first day of its deadline month', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner));
    $this->travelTo(CarbonImmutable::parse('2026-03-28 10:00:00'));
    WorkOrders::hours($this->company, $this->owner, $workOrder, '2', '2026-03-03');
    WorkOrders::hours($this->company, $this->owner, $workOrder, '2', '2026-03-28');

    expect(Advice::invoiceDeadlines($workOrder, CarbonImmutable::parse('2026-04-30')))->toBe([])
        ->and(Advice::invoiceDeadlines($workOrder, CarbonImmutable::parse('2026-05-01')))->toBe([['month' => '2026-03', 'deadline' => '2026-05-31', 'overdue' => false]])
        ->and(Advice::invoiceDeadlines($workOrder, CarbonImmutable::parse('2026-06-01'))[0]['overdue'])->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-05-01 10:00:00'));
    $this->get(route('workOrders.index'))->assertSee('måste faktureras senast 2026-05-31');
});

it('produces the specified basis with hours per day, rates, material and ÄTA (KtjL 40 §, AO-R8)', function () {
    $customer = WorkOrders::privateCustomer($this->company, $this->owner);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $customer);
    WorkOrders::hours($this->company, $this->owner, $workOrder, '1,5', rate: '640');
    approvedChange($workOrder, ['title' => 'Byte av golvbrunn', 'description' => 'Ny brunn.', 'labour' => '2 400']);

    $html = SpecificationPdf::html($workOrder);

    expect($html)->toContain('1,5 tim')->toContain('640,00')->toContain('ÄTA 1')->not->toContain(Customer::query()->find($customer->id)->personal_number);
    $this->get(route('workOrders.specification', $workOrder->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('counts hours from minutes and rounds the hours on the invoice to whole minutes', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner), ['tax_reduction' => 'RUT']);
    WorkOrders::hours($this->company, $this->owner, $workOrder, '0:20');

    $invoice = basis($workOrder);

    expect($invoice->labour_hours_milli)->toBe(333)
        ->and(TimeEntry::query()->where('invoice_id', $invoice->id)->count())->toBe(1);
});
