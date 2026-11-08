<?php

declare(strict_types=1);

/*
 * The cold review of ADR-074 (2026-11-08): locks once an ÄTA is sent, the
 * database triggers for buyers, ÄTA and entries, ÄTA in the year-end
 * figures, the on-site code, prices before an approval, the expiry of a
 * sent ÄTA and the advice at year-end.
 */

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SigningRequest;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Actions\CreateInvoiceBasis;
use App\Modules\WorkOrders\Actions\DecideChangeOrder;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Actions\SaveChangeOrder;
use App\Modules\WorkOrders\Actions\SaveWorkOrder;
use App\Modules\WorkOrders\Actions\SignChangeOrder;
use App\Modules\WorkOrders\Domain\ChangeOrderStatus;
use App\Modules\WorkOrders\Domain\OngoingWork;
use App\Modules\WorkOrders\Livewire\SettingsForm;
use App\Modules\WorkOrders\Livewire\WorkOrderPage;
use App\Modules\WorkOrders\Models\ChangeOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\Support\ChangeOrderPdf;
use App\Modules\WorkOrders\Support\WorkOrdersSigningHook;
use App\Modules\WorkOrders\WorkOrdersModule;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    WorkOrders::enable($this->company, $this->owner);
    $this->customer = WorkOrders::privateCustomer($this->company, $this->owner);
    $this->input = ['customer_id' => $this->customer->id, 'title' => 'Badrum', 'price_form' => 'TIME_AND_MATERIAL', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14'];
    $this->workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer, $this->input);
});

function reviewChange(array $input = []): ChangeOrder
{
    return app(SaveChangeOrder::class)(test()->owner, test()->company, test()->workOrder, ['title' => 'Golvbrunn', 'description' => 'Ny brunn.', 'labour' => '2 400', ...$input]);
}

it('P2-2: locks customer, VAT, ROT/RUT, price form and property once an ÄTA is sent or decided', function (array $change) {
    $save = app(SaveWorkOrder::class);
    $other = WorkOrders::privateCustomer($this->company, $this->owner, 'Olle Exempelsson', '820202234');
    $change = array_map(fn (mixed $value): mixed => $value === 'OTHER' ? $other->id : $value, $change);

    // Free while the ÄTA is a draft.
    reviewChange();
    expect($save($this->owner, $this->company, [...$this->input, 'title' => 'Badrum och hall'], $this->workOrder)->title)->toBe('Badrum och hall');

    app(DecideChangeOrder::class)->approveOrally($this->owner, $this->company, ChangeOrder::query()->firstOrFail(), ['name' => 'Eva', 'channel' => 'PHONE']);

    expect(fn () => $save($this->owner, $this->company, [...$this->input, ...$change], $this->workOrder->fresh()))->toThrow(ValidationException::class, 'skickade ÄTA');
})->with([
    'customer' => [['customer_id' => 'OTHER']],
    'vat' => [['vat_rate' => 1200]],
    'tax reduction' => [['tax_reduction' => 'RUT']],
    'price form' => [['price_form' => 'ESTIMATE', 'approximate_price' => '10 000']],
    'property' => [['property_designation' => 'Annanstans 1:1']],
]);

it('P2-2: takes the ROT rate on the day the ÄTA was sent and keeps the PDF rendered at an oral decision', function () {
    $changeOrder = app(DecideChangeOrder::class)->approveOrally($this->owner, $this->company, reviewChange(), ['name' => 'Eva', 'channel' => 'PHONE']);

    expect($changeOrder->decided_document_path)->not->toBeNull()
        ->and(hash('sha256', ChangeOrderPdf::render($changeOrder)))->toBe($changeOrder->decided_document_sha256);
    Storage::disk('local')->assertExists((string) $changeOrder->decided_document_path);
});

it('P2-3: refuses moving a buyer row to or from an issued invoice in SQL', function () {
    $anna = WorkOrders::privateCustomer($this->company, $this->owner, 'Anna Exempel', '790303345');
    $draft = fn (): Invoice => app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-03-02', 'tax_reduction' => 'ROT', 'property_designation' => 'Exempelstad Ekbacken 3:14',
        'tax_reduction_buyers' => [['customer_id' => $this->customer->id, 'share_bp' => 5000], ['customer_id' => $anna->id, 'share_bp' => 5000]],
        'rows' => [['description' => 'Arbete', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500, 'labour' => true]],
    ]);
    $issued = app(IssueInvoice::class)($this->owner, $draft());
    $open = $draft();
    $issuedRow = DB::table('invoice_tax_reduction_buyers')->where('invoice_id', $issued->id)->value('id');
    $openRow = DB::table('invoice_tax_reduction_buyers')->where('invoice_id', $open->id)->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('invoice_tax_reduction_buyers')->where('id', $issuedRow)->update(['invoice_id' => $open->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('invoice_tax_reduction_buyers')->where('id', $openRow)->update(['invoice_id' => $issued->id])))->toThrow(QueryException::class)
        ->and(DB::table('invoice_tax_reduction_buyers')->where('id', $openRow)->update(['share_bp' => 4000]))->toBe(1);
});

it('P2-4: lets a sent ÄTA go only to a decision, never back to a draft (SQL)', function () {
    $changeOrder = reviewChange();
    DB::table('change_orders')->where('id', $changeOrder->id)->update(['status' => 'SENT']);

    expect(fn () => DB::transaction(fn () => DB::table('change_orders')->where('id', $changeOrder->id)->update(['status' => 'DRAFT'])))->toThrow(QueryException::class)
        ->and(DB::table('change_orders')->where('id', $changeOrder->id)->update(['status' => 'APPROVED_ORALLY', 'oral_channel' => 'PHONE', 'approved_by_name' => 'Eva']))->toBe(1);
});

it('P3-4: refuses a reversal of an invoiced entry and a reservation by an issued invoice (SQL)', function () {
    $entry = WorkOrders::hours($this->company, $this->owner, $this->workOrder, '2');
    $other = WorkOrders::hours($this->company, $this->owner, $this->workOrder, '1');
    $invoice = app(CreateInvoiceBasis::class)($this->owner, $this->company, $this->workOrder);
    DB::table('work_order_time_entries')->where('id', $other->id)->update(['invoice_id' => null]);
    app(IssueInvoice::class)($this->owner, $invoice);

    expect(fn () => DB::transaction(fn () => DB::table('work_order_time_entries')->where('id', $entry->id)->update(['reversed_by_id' => $other->id])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('work_order_time_entries')->where('id', $other->id)->update(['invoice_id' => $invoice->id])))->toThrow(QueryException::class);
});

it('P2-5: counts main work and invoiceable ÄTA only on löpande räkning, fixed-price ÄTA at their amount', function () {
    WorkOrders::hours($this->company, $this->owner, $this->workOrder, '2', rate: '500');
    $held = reviewChange(['title' => 'Inte godkänt', 'price_form' => 'TIME_AND_MATERIAL', 'labour' => '']);
    WorkOrders::hours($this->company, $this->owner, $this->workOrder, '5', changeOrderId: $held->id, rate: '500');
    $fixed = app(DecideChangeOrder::class)->approveOrally($this->owner, $this->company, reviewChange(['labour' => '3 000']), ['name' => 'Eva', 'channel' => 'PHONE']);
    WorkOrders::hours($this->company, $this->owner, $this->workOrder, '9', changeOrderId: $fixed->id, rate: '500');

    $line = OngoingWork::at($this->company, CarbonImmutable::parse('2026-12-31'))[0];

    // 2 h × 500 on the main work + the fixed ÄTA's 3 000; nothing of the unapproved ÄTA, and not the fixed ÄTA's hours.
    expect($line['figures']['performed'])->toBe(400_000)->and($line['figures']['unbilled'])->toBe(400_000);
});

it('P3-1: forgets the link after the fifth wrong code or when the code has expired, and hashes the code with the app key', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $this->workOrder->id, 'user_id' => $worker->id]);
    app(SignChangeOrder::class)->send($this->owner, $this->company, reviewChange());
    $onSite = app(SignChangeOrder::class)->onSite($worker, $this->company, ChangeOrder::query()->firstOrFail());

    expect($onSite->code_hash)->not->toBe(hash('sha256', "{$onSite->signing_request_id}|123456"));

    foreach (range(1, 5) as $try) {
        rescue(fn () => app(SignChangeOrder::class)->verifyOnSite($worker, $this->company, $onSite, 'fel'), report: false);
    }

    expect($onSite->fresh()->url)->toBeNull();

    $second = app(SignChangeOrder::class)->onSite($worker, $this->company, ChangeOrder::query()->firstOrFail());
    $this->travel(16)->minutes();
    rescue(fn () => app(SignChangeOrder::class)->verifyOnSite($worker, $this->company, $second, '000000'), report: false);

    expect($second->fresh()->url)->toBeNull();
});

it('P3-2: refuses an oral approval or performed work for a fixed-price ÄTA without a price', function () {
    $empty = app(SaveChangeOrder::class)($this->owner, $this->company, $this->workOrder, ['title' => 'Utan pris', 'description' => 'x']);
    $decide = app(DecideChangeOrder::class);

    expect(fn () => $decide->approveOrally($this->owner, $this->company, $empty, ['name' => 'Eva', 'channel' => 'PHONE']))->toThrow(ValidationException::class, 'Sätt ett pris');

    $decide->recordAttempt($this->owner, $this->company, $empty, ['channel' => 'PHONE']);
    expect(fn () => $decide->performWithoutAnswer($this->owner, $this->company, $empty, ['reason' => 'URGENT_DAMAGE', 'justification' => 'x']))->toThrow(ValidationException::class, 'Sätt ett pris');

    $hourly = app(SaveChangeOrder::class)($this->owner, $this->company, $this->workOrder, ['title' => 'Löpande', 'description' => 'x', 'price_form' => 'TIME_AND_MATERIAL']);
    expect($decide->approveOrally($this->owner, $this->company, $hourly, ['name' => 'Eva', 'channel' => 'PHONE'])->status)->toBe(ChangeOrderStatus::ApprovedOrally);
});

it('P3-3: lets a field worker change an ÄTA text only until the office has priced it', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $this->workOrder->id, 'user_id' => $worker->id]);
    $own = app(SaveChangeOrder::class)($worker, $this->company, $this->workOrder, ['title' => 'Uttag', 'description' => 'Ett uttag.']);

    expect(app(SaveChangeOrder::class)($worker, $this->company, $this->workOrder, ['title' => 'Två uttag', 'description' => 'Två uttag.'], $own)->title)->toBe('Två uttag');

    $priced = app(SaveChangeOrder::class)($this->owner, $this->company, $this->workOrder, ['title' => 'Två uttag', 'description' => 'Två uttag.', 'labour' => '800'], $own);

    expect(fn () => app(SaveChangeOrder::class)($worker, $this->company, $this->workOrder, ['title' => 'Tre uttag', 'description' => 'x'], $priced))->toThrow(ValidationException::class, 'prissatt');
});

it('P3-6: shows invoice hours with three decimals', function () {
    $invoice = new Invoice;
    $invoice->labour_hours_milli = 333;

    expect($invoice->labourHoursLabel())->toBe('0,333');
});

it('P3-7: warns when the year-end method changes after a closed year, and when the estimate exceeds the price', function () {
    app(ModuleManager::class)->updateSettings(WorkOrdersModule::KEY, $this->company, $this->owner, ['fixed_price_method' => 'K2_MAIN']);
    FiscalYear::query()->update(['status' => 'CLOSED']);

    Livewire::test(SettingsForm::class, ['company' => $this->company])
        ->set('fixedPriceMethod', 'K2_ALTERNATIVE')
        ->call('save')
        ->assertDispatched('toast', type: 'error');

    $fixed = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::business($this->company, $this->owner), ['price_form' => 'FIXED', 'contract_sum' => '100 000']);
    app(ModuleManager::class)->updateSettings(WorkOrdersModule::KEY, $this->company, $this->owner, ['fixed_price_method' => 'K2_MAIN']);
    $line = collect(OngoingWork::at($this->company, CarbonImmutable::parse('2026-12-31'), [$fixed->id => 12_000_000]))->firstWhere('work_order.id', $fixed->id);

    expect($line['note'])->toContain('K2 6.19');
});

it('P3-9: shows a lapsed ÄTA as expired without writing, and the scheduled command marks it expired per company', function () {
    $changeOrder = reviewChange();
    app(SignChangeOrder::class)->send($this->owner, $this->company, $changeOrder);
    [$otherOwner, $otherCompany] = Bookkeeping::company();
    $this->actingAs($this->owner);
    $this->travel(31)->days();

    Livewire::test(WorkOrderPage::class, ['workOrderId' => $this->workOrder->id])->assertSee('Har gått ut');
    expect($changeOrder->fresh()->status)->toBe(ChangeOrderStatus::Sent);

    $this->artisan('work-orders:expire-change-orders')->assertSuccessful();

    CurrentCompany::set($this->company);
    expect($changeOrder->fresh()->status)->toBe(ChangeOrderStatus::Expired)
        ->and(SigningRequest::query()->count())->toBe(1)
        ->and($otherCompany->id)->not->toBe($this->company->id);
});

it('schedules the expiry every fifteen minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'work-orders:expire-change-orders'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *');
});

it('names the supplier-row index the material action recognises (re-check a)', function () {
    $indexes = DB::table('pg_indexes')->where('tablename', 'work_order_material_entries')->pluck('indexname')->all();

    expect($indexes)->toContain(RecordMaterial::SUPPLIER_ROW_INDEX)
        ->and($indexes)->toContain('work_order_material_entries_pkey');
});

it('removes the decision PDF again when the decision rolls back (re-check d)', function () {
    $changeOrder = reviewChange();
    ChangeOrder::saving(function (ChangeOrder $saving): void {
        if ($saving->decided_document_path !== null) {
            throw new RuntimeException('Simulated failure after the PDF was written.');
        }
    });

    expect(fn () => app(DecideChangeOrder::class)->approveOrally($this->owner, $this->company, $changeOrder, ['name' => 'Eva', 'channel' => 'PHONE']))->toThrow(RuntimeException::class);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($changeOrder->fresh()->status)->toBe(ChangeOrderStatus::Draft);
});

it('lets a module refuse the signed copy through the hook, not the core (re-check b)', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    $hook = app(WorkOrdersSigningHook::class);
    $request = new SigningRequest(['document_type' => ChangeOrder::SIGNING_TYPE]);

    expect($hook->mayReceiveCopy($this->company, $worker, $request))->toBeFalse()
        ->and($hook->mayReceiveCopy($this->company, $this->owner, $request))->toBeTrue()
        ->and((string) file_get_contents(app_path('Domain/Signing/SigningFollowUp.php')))->not->toContain('field_worker');
});
