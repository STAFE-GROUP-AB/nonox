<?php

declare(strict_types=1);

/*
 * ADR-074 §8 and the screens: Swedish URLs, toasts, confirmations, empty
 * states; field workers see only assigned work orders and never prices;
 * a fixed number of queries whatever the history (no N+1).
 */

use App\Models\Customer;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Livewire\FieldWorkOrderPage;
use App\Modules\WorkOrders\Livewire\MyWorkOrdersPage;
use App\Modules\WorkOrders\Livewire\OngoingWorkPage;
use App\Modules\WorkOrders\Livewire\SettingsForm;
use App\Modules\WorkOrders\Livewire\WorkOrderEditor;
use App\Modules\WorkOrders\Livewire\WorkOrderPage;
use App\Modules\WorkOrders\Livewire\WorkOrdersPage;
use App\Modules\WorkOrders\Models\TimeEntry;
use App\Modules\WorkOrders\Models\WorkOrder;
use App\Modules\WorkOrders\Models\WorkOrderAssignment;
use App\Modules\WorkOrders\Models\WorkOrderPhoto;
use App\Modules\WorkOrders\WorkOrdersSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\WorkOrders;

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    WorkOrders::enable($this->company, $this->owner);
    $this->customer = WorkOrders::privateCustomer($this->company, $this->owner);
});

it('uses Swedish paths', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer);

    expect(route('workOrders.index', absolute: false))->toBe('/arbetsorder')
        ->and(route('workOrders.mine', absolute: false))->toBe('/arbetsorder/mina')
        ->and(route('workOrders.ongoing', absolute: false))->toBe('/arbetsorder/pagaende-arbeten')
        ->and(route('workOrders.field', $workOrder->id, absolute: false))->toBe("/arbetsorder/{$workOrder->id}/pa-plats");

    foreach (['workOrders.index', 'workOrders.create', 'workOrders.mine', 'workOrders.ongoing', 'workOrders.settings'] as $route) {
        $this->get(route($route))->assertOk();
    }
});

it('creates a work order in the form and lands on it with a toast', function () {
    Livewire::test(WorkOrderEditor::class)
        ->set('form.customer_id', (string) $this->customer->id)
        ->set('form.title', 'Målning av hall')
        ->set('form.price_form', 'ESTIMATE')
        ->set('form.approximate_price', '12 000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('workOrders.show', WorkOrder::query()->where('title', 'Målning av hall')->value('id')));

    expect(session('toasts'))->not->toBeEmpty();
});

it('shows a form error and an error toast when the work order cannot be saved', function () {
    Livewire::test(WorkOrderEditor::class)
        ->set('form.customer_id', (string) $this->customer->id)
        ->set('form.title', 'Fast pris utan summa')
        ->set('form.price_form', 'FIXED')
        ->call('save')
        ->assertHasErrors('form.contract_sum')
        ->assertDispatched('toast', type: 'error');
});

it('lets the office record time and create the invoice basis from the work order page', function () {
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer);

    Livewire::test(WorkOrderPage::class, ['workOrderId' => $workOrder->id])
        ->assertSee('Ingen tid registrerad')
        ->set('time.hours', '2')
        ->set('time.rate', '600')
        ->call('recordTime')
        ->assertDispatched('toast', message: 'Tiden är registrerad.')
        ->call('createBasis')
        ->assertRedirect();

    expect(TimeEntry::query()->whereNotNull('invoice_id')->count())->toBe(1);
});

it('gives a field worker only assigned work orders, the field screen instead of prices, and reports by stepper', function () {
    $worker = Bookkeeping::member($this->company, 'field_worker');
    $mine = WorkOrders::workOrder($this->company, $this->owner, $this->customer, ['title' => 'Mitt jobb', 'price_form' => 'FIXED', 'contract_sum' => '77 777', 'user_ids' => [$worker->id], 'access_notes' => 'Portkod 2468']);
    $other = WorkOrders::workOrder($this->company, $this->owner, $this->customer, ['title' => 'Annat jobb']);
    $this->actingAs($worker);

    Livewire::test(WorkOrdersPage::class)->assertSee('Mitt jobb')->assertDontSee('Annat jobb');
    Livewire::test(MyWorkOrdersPage::class)->assertSee('Mitt jobb')->assertSee('Ingen tid idag');
    $this->get(route('workOrders.show', $mine->id))->assertRedirect(route('workOrders.field', $mine->id));
    $this->get(route('workOrders.show', $other->id))->assertNotFound();
    $this->get(route('workOrders.field', $other->id))->assertNotFound();

    $html = $this->get(route('workOrders.field', $mine->id))->assertOk()->assertSee('Portkod 2468')->getContent();
    expect($html)->not->toContain('77 777')->not->toContain((string) Customer::query()->find($this->customer->id)->personal_number);

    Livewire::test(FieldWorkOrderPage::class, ['workOrderId' => $mine->id])
        ->call('step', 15)->call('step', 15)->call('step', 60)
        ->assertSet('time.hours', '1,5')
        ->call('recordTime')
        ->assertDispatched('toast', message: 'Tiden är registrerad.')
        ->assertSee('Mitt idag');

    expect(TimeEntry::query()->where('user_id', $worker->id)->value('minutes'))->toBe(90)
        ->and(TimeEntry::query()->where('user_id', $worker->id)->value('source'))->toBe('MOBILE');
});

it('stores a photo on the private disk and serves it only through the work order', function () {
    Storage::fake('local');
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, $this->customer);

    Livewire::test(FieldWorkOrderPage::class, ['workOrderId' => $workOrder->id])
        ->set('tab', 'foto')
        ->set('photo', UploadedFile::fake()->image('vagg.php.jpg'))
        ->set('photoCaption', 'Väggen innan')
        ->call('uploadPhoto')
        ->assertDispatched('toast', message: 'Bilden är sparad.');

    $photo = WorkOrderPhoto::query()->firstOrFail();
    expect($photo->path)->toEndWith('.jpg')->not->toContain('.php');
    Storage::disk('local')->assertExists($photo->path);
    $this->get(route('workOrders.photo', [$workOrder->id, $photo->id]))->assertOk();
});

it('saves the settings and a new kind of work', function () {
    Livewire::test(SettingsForm::class, ['company' => $this->company])
        ->set('autoProject', false)
        ->set('markup', '12,5')
        ->set('fixedPriceMethod', 'K2_MAIN')
        ->call('save')
        ->assertDispatched('toast', message: 'Inställningarna för arbetsorder är sparade.')
        ->call('createKind')
        ->set('form.code', 'MASKIN')->set('form.name', 'Minigrävare')->set('form.type', 'MACHINE')->set('form.unit', 'HOUR')->set('form.default_rate', '900')
        ->call('saveKind')
        ->assertDispatched('toast', message: 'Typen av arbete är tillagd.');

    expect(WorkOrdersSettings::autoProject($this->company))->toBeFalse()
        ->and(WorkOrdersSettings::markupBp($this->company))->toBe(1250)
        ->and(WorkOrders::kind('MASKIN')->default_rate_ore->ore)->toBe(90_000);
});

it('shows the ongoing work page with an empty state', function () {
    Livewire::test(OngoingWorkPage::class)->set('date', '2026-12-31')->assertSee('Inga pågående arbeten på balansdagen');
});

it('loads the list and the work order page with a fixed number of queries (no N+1)', function () {
    $count = function (callable $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = WorkOrders::workOrder($this->company, $this->owner, $this->customer);
    WorkOrders::hours($this->company, $this->owner, $short, '1');
    app(RecordMaterial::class)($this->owner, $this->company, $short, ['source' => 'FREE', 'description' => 'Material', 'quantity' => '1', 'unit_cost' => '10', 'work_date' => '2026-03-02']);
    $long = WorkOrders::workOrder($this->company, $this->owner, $this->customer);

    foreach (range(1, 8) as $day) {
        WorkOrders::hours($this->company, $this->owner, $long, '1', sprintf('2026-02-%02d', $day + 10));
        app(RecordMaterial::class)($this->owner, $this->company, $long, ['source' => 'FREE', 'description' => "Material {$day}", 'quantity' => '1', 'unit_cost' => '10', 'work_date' => '2026-03-02']);
    }

    $this->get(route('workOrders.show', $short->id))->assertOk(); // warm the user's memoised teams
    $shortPage = $count(fn () => $this->get(route('workOrders.show', $short->id))->assertOk());
    $longPage = $count(fn () => $this->get(route('workOrders.show', $long->id))->assertOk());
    expect($longPage)->toBe($shortPage);

    $fewList = $count(fn () => $this->get(route('workOrders.index'))->assertOk());

    foreach (range(1, 6) as $index) {
        WorkOrders::hours($this->company, $this->owner, WorkOrders::workOrder($this->company, $this->owner, $this->customer, ['title' => "Jobb {$index}"]), '1');
    }

    expect($count(fn () => $this->get(route('workOrders.index'))->assertOk()))->toBe($fewList);

    $worker = Bookkeeping::member($this->company, 'field_worker');
    WorkOrderAssignment::create(['work_order_id' => $short->id, 'user_id' => $worker->id]);
    $this->actingAs($worker);
    $this->get(route('workOrders.mine'))->assertOk();
    $oneMine = $count(fn () => $this->get(route('workOrders.mine'))->assertOk());
    WorkOrderAssignment::create(['work_order_id' => $long->id, 'user_id' => $worker->id]);
    expect($count(fn () => $this->get(route('workOrders.mine'))->assertOk()))->toBe($oneMine);
});
