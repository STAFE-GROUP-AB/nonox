<?php

declare(strict_types=1);

/*
 * ADR-074 §3: time and material entries are append-only, corrected by a
 * reversing entry with a reason; an invoiced entry cannot be reversed.
 * Stock issued through Lager is a WORK_ORDER_ISSUE movement and comes back
 * on reversal; a supplier invoice row is billed on once. Field workers
 * report only on assigned work orders and never set prices.
 */

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Models\Project;
use App\Models\SupplierInvoiceRow;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\RecordTime;
use App\Modules\WorkOrders\Actions\ReverseEntry;
use App\Modules\WorkOrders\Actions\SaveWorkOrder;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\WorkOrders;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    WorkOrders::enable($this->company, $this->owner);
    $this->customer = WorkOrders::privateCustomer($this->company, $this->owner);
    $this->workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer, ['rates' => [WorkOrders::kind()->id => '650']]);
});

it('records hours at the work order\'s rate, once per device id', function () {
    $id = (string) Str::uuid();
    $input = ['id' => $id, 'labour_kind_id' => WorkOrders::kind()->id, 'work_date' => '2026-03-02', 'hours' => '1:30', 'source' => 'MOBILE'];

    $entry = app(RecordTime::class)($this->owner, $this->company, $this->workOrder, $input);
    $again = app(RecordTime::class)($this->owner, $this->company, $this->workOrder, $input);

    expect($entry->minutes)->toBe(90)
        ->and($entry->rate_ore)->toBe(65_000)
        ->and($entry->netOre())->toBe(97_500)
        ->and($again->id)->toBe($id)
        ->and(TimeEntry::query()->count())->toBe(1);
});

it('records travel per trip and refuses hours over 24', function () {
    $trip = app(RecordTime::class)($this->owner, $this->company, $this->workOrder, ['labour_kind_id' => WorkOrders::kind('RESA')->id, 'work_date' => '2026-03-02', 'quantity' => '1']);

    expect($trip->minutes)->toBeNull()->and($trip->quantity_milli)->toBe(1000);
    expect(fn () => WorkOrders::hours($this->company, $this->owner, $this->workOrder, '25'))->toThrow(ValidationException::class);
});

it('never changes or removes an entry, through Eloquent or SQL (append-only)', function () {
    $entry = WorkOrders::hours($this->company, $this->owner, $this->workOrder, '2');

    expect(fn () => $entry->forceFill(['minutes' => 60])->save())->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
    expect(fn () => DB::transaction(fn () => DB::table('work_order_time_entries')->where('id', $entry->id)->update(['minutes' => 60])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('work_order_time_entries')->where('id', $entry->id)->delete()))->toThrow(QueryException::class);
});

it('corrects an entry by a reversal with a reason, once', function () {
    $entry = WorkOrders::hours($this->company, $this->owner, $this->workOrder, '2');

    expect(fn () => app(ReverseEntry::class)($this->owner, $this->company, $entry, ''))->toThrow(ValidationException::class, 'varför');

    $reversal = app(ReverseEntry::class)($this->owner, $this->company, $entry, 'Fel arbetsorder');

    expect($reversal->minutes)->toBe(-120)
        ->and($reversal->reverses_id)->toBe($entry->id)
        ->and($entry->fresh()->reversed_by_id)->toBe($reversal->id);

    expect(fn () => app(ReverseEntry::class)($this->owner, $this->company, $entry->fresh(), 'Igen'))->toThrow(ValidationException::class, 'redan backad');
    expect(fn () => DB::transaction(fn () => DB::table('work_order_time_entries')->where('id', $entry->id)->update(['reversed_by_id' => null])))->toThrow(QueryException::class);
});

it('issues stock as WORK_ORDER_ISSUE and returns it on reversal (ADR-039, ADR-074 §3)', function () {
    Inventory::enable($this->company, $this->owner);
    $places = Inventory::locations($this->company, $this->owner);
    $article = Inventory::article($this->company, $this->owner, 'GB-1', 'Golvbrunn', ['purchase_price' => '800,00', 'price' => '1200,00']);
    Inventory::receive($this->company, $this->owner, $article, $places['bin1'], '5', '800', '2026-02-01');

    $entry = app(RecordMaterial::class)($this->owner, $this->company, $this->workOrder, ['source' => 'STOCK', 'article_id' => $article->id, 'location_id' => $places['bin1']->id, 'quantity' => '2', 'work_date' => '2026-03-02']);

    expect(Inventory::level($article, $places['bin1']))->toBe(3000)
        ->and($entry->unit_price_ore)->toBe(120_000)
        ->and(StockMovement::query()->where('type', MovementType::WorkOrderIssue->value)->where('source_id', $entry->id)->value('quantity_milli'))->toBe(-2000);

    app(ReverseEntry::class)($this->owner, $this->company, $entry, 'Fel artikel');

    expect(Inventory::level($article, $places['bin1']))->toBe(5000);
});

it('bills a row of an approved supplier invoice on once, at cost plus the mark-up', function () {
    $row = workOrderSupplierRow($this, approve: true);
    $this->workOrder = app(SaveWorkOrder::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'Badrumsrenovering', 'price_form' => 'TIME_AND_MATERIAL', 'material_markup' => '10'], $this->workOrder);

    $entry = app(RecordMaterial::class)($this->owner, $this->company, $this->workOrder, ['source' => 'SUPPLIER_INVOICE_ROW', 'supplier_invoice_row_id' => $row->id, 'work_date' => '2026-03-02']);

    expect($entry->unit_cost_ore)->toBe(100_000)->and($entry->unit_price_ore)->toBe(110_000);
    expect(fn () => app(RecordMaterial::class)($this->owner, $this->company, $this->workOrder, ['source' => 'SUPPLIER_INVOICE_ROW', 'supplier_invoice_row_id' => $row->id, 'work_date' => '2026-03-02']))
        ->toThrow(ValidationException::class, 'redan vidarefakturerad');

    // After a reversal the row can be billed again.
    app(ReverseEntry::class)($this->owner, $this->company, $entry, 'Fel arbetsorder');
    expect(app(RecordMaterial::class)($this->owner, $this->company, $this->workOrder, ['source' => 'SUPPLIER_INVOICE_ROW', 'supplier_invoice_row_id' => $row->id, 'work_date' => '2026-03-02'])->supplier_invoice_row_id)->toBe($row->id);
});

it('refuses a row of a supplier invoice that is not approved', function () {
    $row = workOrderSupplierRow($this, approve: false);

    expect(fn () => app(RecordMaterial::class)($this->owner, $this->company, $this->workOrder, ['source' => 'SUPPLIER_INVOICE_ROW', 'supplier_invoice_row_id' => $row->id, 'work_date' => '2026-03-02']))
        ->toThrow(ValidationException::class, 'attesterade');
});

it('lets a field worker report only on assigned work orders, at the work order\'s price', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    $other = WorkOrders::workOrder($this->company, $this->owner, $this->customer);
    app(SaveWorkOrder::class)($this->owner, $this->company, ['customer_id' => $this->customer->id, 'title' => 'Badrumsrenovering', 'price_form' => 'TIME_AND_MATERIAL', 'rates' => [WorkOrders::kind()->id => '650'], 'user_ids' => [$worker->id]], $this->workOrder);
    $this->actingAs($worker);

    $entry = app(RecordTime::class)($worker, $this->company, $this->workOrder, ['labour_kind_id' => WorkOrders::kind()->id, 'work_date' => '2026-03-02', 'hours' => '2', 'rate' => '1', 'billable' => false]);

    expect($entry->rate_ore)->toBe(65_000)->and($entry->billable)->toBeTrue();
    expect(fn () => app(RecordTime::class)($worker, $this->company, $other, ['labour_kind_id' => WorkOrders::kind()->id, 'work_date' => '2026-03-02', 'hours' => '1']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReverseEntry::class)($worker, $this->company, WorkOrders::hours($this->company, $this->owner, $this->workOrder, '1'), 'Inte min'))
        ->toThrow(ValidationException::class, 'egna');
});

function workOrderSupplierRow(object $test, bool $approve): SupplierInvoiceRow
{
    CurrentCompany::set($test->company);
    $test->company->forceFill(['separate_supplier_approval' => false])->save();
    $supplier = app(SaveSupplier::class)($test->owner, $test->company, ['name' => 'Rörgrossisten Exempel AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
    $draft = app(SaveSupplierInvoice::class)($test->owner, $test->company, [
        'supplier_id' => $supplier->id, 'invoice_date' => '2026-03-01', 'total' => '1250', 'vat' => '250', 'vat_treatment' => 'DOMESTIC',
        'rows' => [['account' => '4010', 'amount' => '1000', 'vat_rate' => '2500', 'project' => Project::query()->findOrFail($test->workOrder->project_id)->code, 'description' => 'Golvbrunn']],
    ]);
    $invoice = app(RegisterSupplierInvoice::class)($test->owner, $draft);

    if ($approve) {
        app(ApproveSupplierInvoice::class)($test->owner, $invoice);
    }

    return SupplierInvoiceRow::query()->where('supplier_invoice_id', $invoice->id)->firstOrFail();
}
