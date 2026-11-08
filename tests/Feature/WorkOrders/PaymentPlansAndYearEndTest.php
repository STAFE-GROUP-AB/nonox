<?php

declare(strict_types=1);

/*
 * ADR-074 §6 and §7 with fixtures F7a, F7b and F9: a conto instalments
 * with ROT on their own labour share (AO-R16) and a final invoice for the
 * remainder only (L14); the warning when a ROT a conto paid one year has
 * no finished work by 31 January (F9); and "Pågående arbeten" that only
 * proposes draft vouchers (AO-R30–R33).
 */

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Invoicing\InvoicePdf;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\DecideChangeOrder;
use App\Modules\WorkOrders\Actions\PaymentPlans;
use App\Modules\WorkOrders\Actions\ProposeOngoingWorkVouchers;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Domain\Advice;
use App\Modules\WorkOrders\Domain\OngoingWork;
use App\Modules\WorkOrders\Models\PaymentInstalment;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
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

function fixedRot(): WorkOrder
{
    return WorkOrders::workOrder(test()->company, test()->owner, WorkOrders::privateCustomer(test()->company, test()->owner), [
        'price_form' => 'FIXED', 'contract_sum' => '64 000', 'contract_labour' => '40 000', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
    ]);
}

/** A plain issued invoice to the work order's customer, linked as an a conto invoice. */
function linkedAconto(WorkOrder $workOrder, string $net, string $date): void
{
    $invoice = app(SaveDraftInvoice::class)(test()->owner, test()->company, ['customer_id' => $workOrder->customer_id, 'invoice_date' => $date, 'rows' => [['description' => 'A conto', 'quantity' => '1', 'price' => $net, 'vat_rate' => 2500]]]);
    app(IssueInvoice::class)(test()->owner, $invoice);
    WorkOrderInvoice::create(['work_order_id' => $workOrder->id, 'invoice_id' => $invoice->id, 'kind' => WorkOrderInvoice::INSTALMENT, 'contract_net_ore' => Money::parse($net)->ore]);
}

it('gives each a conto invoice its own ROT on its labour share and the final invoice the remainder (AO-R16, L14)', function () {
    $workOrder = fixedRot();
    app(PaymentPlans::class)->save($this->owner, $this->company, $workOrder, [['label' => 'Vid start', 'amount' => '32 000', 'due_on' => '2026-03-02']], agreedWithCustomer: true);
    $instalment = PaymentInstalment::query()->where('work_order_id', $workOrder->id)->firstOrFail();

    $aconto = app(IssueInvoice::class)($this->owner, app(PaymentPlans::class)->invoice($this->owner, $this->company, $instalment));

    // 32 000 of 64 000: labour 20 000 ex VAT, 25 000 incl.; 30 % = 7 500.
    expect($aconto->net_ore->ore)->toBe(3_200_000)
        ->and($aconto->tax_reduction_ore->ore)->toBe(750_000)
        ->and($aconto->rows->first()->description)->toContain('A conto: Vid start');

    WorkOrders::hours($this->company, $this->owner, $workOrder, '8');
    $final = app(CreateInvoiceBasis::class)($this->owner, $this->company, $workOrder);

    expect($final->net_ore->ore)->toBe(3_200_000)
        ->and($final->rows->every(fn ($row) => $row->net_ore->ore > 0))->toBeTrue()
        ->and($final->tax_reduction_ore->ore)->toBe(750_000)
        ->and(WorkOrderInvoice::query()->where('invoice_id', $final->id)->value('kind'))->toBe(WorkOrderInvoice::FINAL);
});

it('claims the hours recorded before an a conto invoice on it, and still makes the final invoice (review P1-2, hours first)', function () {
    $workOrder = fixedRot();
    WorkOrders::hours($this->company, $this->owner, $workOrder, '8');
    app(PaymentPlans::class)->save($this->owner, $this->company, $workOrder, [['label' => 'Vid start', 'amount' => '32 000']], agreedWithCustomer: true);

    $aconto = app(IssueInvoice::class)($this->owner, app(PaymentPlans::class)->invoice($this->owner, $this->company, PaymentInstalment::query()->where('work_order_id', $workOrder->id)->firstOrFail()));
    $final = app(CreateInvoiceBasis::class)($this->owner, $this->company, $workOrder);

    expect($aconto->labour_hours_milli)->toBe(8000)
        ->and(TimeEntry::query()->where('work_order_id', $workOrder->id)->value('invoice_id'))->toBe($final->id)
        ->and($final->labour_hours_milli)->toBeNull()
        ->and($final->labour_hours_reported_on)->toBe([$aconto->id])
        ->and(InvoicePdf::html($final))->toContain("Arbetade timmar: redovisade på faktura {$aconto->number}")
        ->and($final->tax_reduction_ore->ore)->toBe(750_000);
});

it('claims on the final invoice the hours recorded after an a conto invoice without hours (review P1-2, hours after)', function () {
    $workOrder = fixedRot();
    app(PaymentPlans::class)->save($this->owner, $this->company, $workOrder, [['label' => 'Förskott', 'amount' => '32 000']], agreedWithCustomer: true);
    $aconto = app(IssueInvoice::class)($this->owner, app(PaymentPlans::class)->invoice($this->owner, $this->company, PaymentInstalment::query()->where('work_order_id', $workOrder->id)->firstOrFail()));

    expect($aconto->labour_hours_milli)->toBe(0);
    expect(fn () => app(CreateInvoiceBasis::class)($this->owner, $this->company, $workOrder))->toThrow(ValidationException::class, 'Registrera arbetade timmar');

    WorkOrders::hours($this->company, $this->owner, $workOrder, '6');

    expect(app(CreateInvoiceBasis::class)($this->owner, $this->company, $workOrder)->labour_hours_milli)->toBe(6000);
});

it('asks a consumer plan to be agreed, needs a fixed price, and keeps the plan below the contract sum', function () {
    $workOrder = fixedRot();
    $plans = app(PaymentPlans::class);

    expect(fn () => $plans->save($this->owner, $this->company, $workOrder, [['amount' => '10 000']], agreedWithCustomer: false))->toThrow(ValidationException::class, 'särskild överenskommelse');
    expect(fn () => $plans->save($this->owner, $this->company, $workOrder, [['amount' => '64 000']], agreedWithCustomer: true))->toThrow(ValidationException::class, 'mindre än det fasta priset');

    $hourly = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner, 'Per Exempel', '790303345'));
    expect(fn () => $plans->save($this->owner, $this->company, $hourly, [['amount' => '1 000']], agreedWithCustomer: true))->toThrow(ValidationException::class, 'fast pris');
});

it('F9: warns when a ROT a conto paid in December has no finished work by 31 January', function () {
    $workOrder = fixedRot();
    app(PaymentPlans::class)->save($this->owner, $this->company, $workOrder, [['label' => 'Förskott', 'amount' => '10 000']], agreedWithCustomer: true);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 10:00:00'));
    $invoice = app(IssueInvoice::class)($this->owner, app(PaymentPlans::class)->invoice($this->owner, $this->company, PaymentInstalment::query()->where('work_order_id', $workOrder->id)->firstOrFail()));
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-12-20'), $invoice->total_ore);

    expect(Advice::rotAconto($workOrder->fresh(), CarbonImmutable::parse('2027-01-31')))->toBe([])
        ->and(Advice::rotAconto($workOrder->fresh(), CarbonImmutable::parse('2027-02-01')))->toBe([['invoice_number' => $invoice->number, 'paid_year' => 2026]]);
});

it('F7a: proposes unbilled work as revenue and an a conto as an advance, both reversed, on löpande räkning', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner));
    $this->travelTo(CarbonImmutable::parse('2026-12-31 10:00:00'));
    WorkOrders::hours($this->company, $this->owner, $workOrder, '12', '2026-12-30', rate: '650');
    app(RecordMaterial::class)($this->owner, $this->company, $workOrder, ['source' => 'FREE', 'description' => 'Kabel', 'quantity' => '1', 'unit_cost' => '1500', 'unit_price' => '2000', 'work_date' => '2026-12-30']);
    linkedAconto($workOrder, '20 000', '2026-12-15');

    $line = OngoingWork::at($this->company, CarbonImmutable::parse('2026-12-31'))[0];

    expect($line['figures']['unbilled'])->toBe(980_000)
        ->and($line['figures']['ahead'])->toBe(2_000_000)
        ->and($line['rows'])->toBe([
            ['account' => 1620, 'debit_ore' => 980_000, 'credit_ore' => 0],
            ['account' => 3001, 'debit_ore' => 0, 'credit_ore' => 980_000],
            ['account' => 3001, 'debit_ore' => 2_000_000, 'credit_ore' => 0],
            ['account' => 2420, 'debit_ore' => 0, 'credit_ore' => 2_000_000],
        ]);

    [$adjustment, $reversal] = app(ProposeOngoingWorkVouchers::class)($this->owner, $this->company, CarbonImmutable::parse('2026-12-31'));

    expect($adjustment->status)->toBe(VoucherStatus::Draft)
        ->and($reversal->status)->toBe(VoucherStatus::Draft)
        ->and($adjustment->voucher_date->toDateString())->toBe('2026-12-31')
        ->and($reversal->voucher_date->toDateString())->toBe('2027-01-01')
        ->and($reversal->rows()->whereHas('account', fn ($query) => $query->where('number', 1620))->first()?->credit_ore->ore)->toBe(980_000);
});

it('F7b: counts an hourly ÄTA into the fixed-price job and proposes revenue by degree of completion (main rule)', function () {
    app(ModuleManager::class)->updateSettings(WorkOrdersModule::KEY, $this->company, $this->owner, ['fixed_price_method' => 'K2_MAIN']);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::business($this->company, $this->owner, 'Fastighetsbolaget Exempel AB', '559911223'), ['price_form' => 'FIXED', 'contract_sum' => '300 000']);
    $ata = app(SaveChangeOrder::class)($this->owner, $this->company, $workOrder, ['title' => 'Extra rum', 'description' => 'Löpande.', 'price_form' => 'TIME_AND_MATERIAL']);
    app(DecideChangeOrder::class)->approveOrally($this->owner, $this->company, $ata, ['name' => 'Inköparen', 'channel' => 'EMAIL']);
    WorkOrders::hours($this->company, $this->owner, $workOrder, '10', '2026-03-02', $ata->id, '1200');

    $code = Project::query()->findOrFail($workOrder->project_id)->code;
    app(PostVoucher::class)($this->owner, app(SaveDraftVoucher::class)($this->owner, $this->company, ['voucher_date' => '2026-03-02', 'description' => 'Kostnader', 'rows' => [
        ['account' => 4010, 'debit_ore' => 18_000_000, 'project' => $code],
        ['account' => 1930, 'credit_ore' => 18_000_000],
    ]]));
    linkedAconto($workOrder, '200 000', '2026-03-02');

    $line = OngoingWork::at($this->company, CarbonImmutable::parse('2026-12-31'), [$workOrder->id => 24_000_000])[0];

    expect($line['figures']['contract'])->toBe(31_200_000)
        ->and($line['figures']['degree_bp'])->toBe(7500)
        ->and($line['figures']['earned'])->toBe(23_400_000)
        ->and($line['rows'])->toBe([
            ['account' => 1620, 'debit_ore' => 3_400_000, 'credit_ore' => 0],
            ['account' => 3001, 'debit_ore' => 0, 'credit_ore' => 3_400_000],
        ]);

    app(ModuleManager::class)->updateSettings(WorkOrdersModule::KEY, $this->company, $this->owner, ['fixed_price_method' => 'K2_ALTERNATIVE']);
    $alternative = OngoingWork::at($this->company, CarbonImmutable::parse('2026-12-31'), [$workOrder->id => 24_000_000])[0];

    expect($alternative['rows'])->toBe([
        ['account' => 3001, 'debit_ore' => 20_000_000, 'credit_ore' => 0],
        ['account' => 2450, 'debit_ore' => 0, 'credit_ore' => 20_000_000],
    ])->and($alternative['note'])->toContain('1470');
});

it('shows the figures without a proposal until a method is chosen, and adds a non-blocking point to the year-end checklist', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner), ['price_form' => 'FIXED', 'contract_sum' => '10 000']);
    linkedAconto($workOrder, '5 000', '2026-03-02');

    $line = OngoingWork::at($this->company, CarbonImmutable::parse('2026-12-31'))[0];
    expect($line['rows'])->toBe([])->and($line['note'])->toContain('Välj metod');

    CurrentCompany::set($this->company);
    $item = collect(ClosingChecklist::for($this->company, FiscalYear::query()->firstOrFail()))->firstWhere('key', 'ongoing_work');

    expect($item['status'])->toBe(ClosingChecklist::WARN)
        ->and(ClosingChecklist::isComplete([$item]))->toBeTrue();
});
