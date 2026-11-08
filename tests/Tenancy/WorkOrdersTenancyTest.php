<?php

declare(strict_types=1);

use App\Models\InvoiceTaxReductionBuyer;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\DecideChangeOrder;
use App\Modules\WorkOrders\Actions\PaymentPlans;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Livewire\FieldWorkOrderPage;
use App\Modules\WorkOrders\Livewire\WorkOrderPage;
use App\Modules\WorkOrders\Livewire\WorkOrdersPage;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\ContactAttempt;
use App\Modules\WorkOrders\Models\LabourKind;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Modules\WorkOrders\Models\PaymentInstalment;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\Models\WorkOrderBuyer;
use App\Modules\WorkOrders\Models\WorkOrderInvoice;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

/*
 * AGENTS.md §13.2 for Arbetsorder (ADR-074): company A never sees B's work
 * orders, entries, ÄTA, attempts, plans, invoices, photos, buyers or kinds
 * through Eloquent, the pages, the PDFs or the actions; B's ids answer 404.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));

    foreach (['a' => 'Alfa', 'b' => 'Beta'] as $side => $name) {
        [$owner, $company] = Bookkeeping::company();
        $this->actingAs($owner);
        WorkOrders::enable($company, $owner);
        $eva = WorkOrders::privateCustomer($company, $owner, "{$name} Kund");
        $olle = WorkOrders::privateCustomer($company, $owner, "{$name} Make", '820202234');
        $workOrder = WorkOrders::workOrder($company, $owner, $eva, [
            'title' => "{$name}jobbet", 'price_form' => 'FIXED', 'contract_sum' => '10 000', 'contract_labour' => '6 000',
            'tax_reduction' => 'ROT', 'property_designation' => "{$name}stad 1:1",
            'buyers' => [['customer_id' => $eva->id, 'share' => '50'], ['customer_id' => $olle->id, 'share' => '50']],
            'user_ids' => [$owner->id],
        ]);
        $entry = WorkOrders::hours($company, $owner, $workOrder, '2');
        app(RecordMaterial::class)($owner, $company, $workOrder, ['source' => 'FREE', 'description' => "{$name}material", 'quantity' => '1', 'unit_cost' => '10', 'work_date' => '2026-03-02']);
        $changeOrder = app(SaveChangeOrder::class)($owner, $company, $workOrder, ['title' => "{$name}-ÄTA", 'description' => 'Extra.']);
        app(DecideChangeOrder::class)->recordAttempt($owner, $company, $changeOrder, ['channel' => 'PHONE']);
        app(PaymentPlans::class)->save($owner, $company, $workOrder, [['label' => 'Start', 'amount' => '1 000']], agreedWithCustomer: true);
        $invoice = app(CreateInvoiceBasis::class)($owner, $company, $workOrder);
        WorkOrderPhoto::create(['work_order_id' => $workOrder->id, 'path' => "arbetsorder/{$name}.jpg"]);
        $this->{$side} = compact('owner', 'company', 'workOrder', 'entry', 'changeOrder', 'invoice');
    }

    CurrentCompany::set($this->a['company']);
    $this->actingAs($this->a['owner']);
});

it('scopes every Arbetsorder model to the current company', function (string $model) {
    expect($model::query()->count())->toBeGreaterThan(0)
        ->and($model::query()->pluck('company_id')->unique()->all())->toBe([$this->a['company']->id]);
})->with([
    WorkOrder::class, LabourKind::class, WorkOrderAssignment::class, WorkOrderBuyer::class, TimeEntry::class, MaterialEntry::class,
    ChangeOrder::class, ContactAttempt::class, PaymentInstalment::class, WorkOrderInvoice::class, WorkOrderPhoto::class, InvoiceTaxReductionBuyer::class,
]);

it('answers 404 for the other company\'s work orders, ÄTA, documents and photos', function () {
    $b = $this->b['workOrder'];

    expect(WorkOrder::query()->find($b->id))->toBeNull()
        ->and(TimeEntry::query()->find($this->b['entry']->id))->toBeNull();

    $this->get(route('workOrders.show', $b->id))->assertNotFound();
    $this->get(route('workOrders.edit', $b->id))->assertNotFound();
    $this->get(route('workOrders.field', $b->id))->assertNotFound();
    $this->get(route('workOrders.specification', $b->id))->assertNotFound();
    $this->get(route('workOrders.changeOrderPdf', [$b->id, $this->b['changeOrder']->id]))->assertNotFound();
    $this->get(route('workOrders.changeOrderPdf', [$this->a['workOrder']->id, $this->b['changeOrder']->id]))->assertNotFound();
    $this->get(route('workOrders.photo', [$b->id, 1]))->assertNotFound();
    $this->get(route('workOrders.show', $this->a['workOrder']->id))->assertOk()->assertSee('Alfajobbet')->assertDontSee('Betajobbet');

    Livewire::test(WorkOrderPage::class, ['workOrderId' => $b->id])->assertNotFound();
    Livewire::test(FieldWorkOrderPage::class, ['workOrderId' => $b->id])->assertNotFound();
});

it('shows only the own company\'s work orders in the list', function () {
    Livewire::test(WorkOrdersPage::class)->assertSee('Alfajobbet')->assertDontSee('Betajobbet');
});

it('refuses actions on the other company\'s records', function () {
    Livewire::test(WorkOrderPage::class, ['workOrderId' => $this->a['workOrder']->id])
        ->call('editChange', $this->b['changeOrder']->id)->assertNotFound();
    Livewire::test(WorkOrderPage::class, ['workOrderId' => $this->a['workOrder']->id])
        ->set('reversing', 'time:'.$this->b['entry']->id)->set('reason', 'x')->call('reverse')->assertNotFound();

    CurrentCompany::set($this->b['company']);
    expect($this->b['entry']->fresh()->reversed_by_id)->toBeNull();
});
