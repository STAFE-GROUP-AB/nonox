<?php

declare(strict_types=1);

use App\Models\SalesDocument;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\Livewire\ManufacturingSettingsForm;
use App\Modules\Manufacturing\Livewire\Production\CreateProductionOrderPage;
use App\Modules\Manufacturing\Livewire\Production\ProductionOrderPage;
use App\Modules\Manufacturing\Livewire\Production\ProductionOrdersPage;
use App\Modules\Manufacturing\Livewire\RowStructuresPanel;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Manufacturing;

/*
 * The production order screens (ADR-071 §8): the list under
 * /tillverkning/tillverkningsorder with its Swedish filters, creating from
 * an article and from a customer order row, the order page with its status
 * actions and Swedish toasts, the read-only view for view, the settings,
 * and a fixed number of queries for the list and the order page.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
});

function pagesOrder(object $test, string $quantity = '2'): ProductionOrder
{
    return app(SaveProductionOrder::class)->create($test->owner, $test->company, ['article_id' => $test->fx['articles']['table']->id, 'quantity' => $quantity, 'location_id' => $test->places['bin2']->id]);
}

it('serves the pages under Swedish addresses and lists the open orders by default', function () {
    $open = pagesOrder($this);
    $done = pagesOrder($this, '1');
    $life = app(ProductionOrderLifecycle::class);
    $life->release($this->owner, $this->company, $done);
    $life->start($this->owner, $this->company, $done);
    $life->finish($this->owner, $this->company, $done);

    expect(route('manufacturing.productionOrders.index', absolute: false))->toBe('/tillverkning/tillverkningsorder')
        ->and(route('manufacturing.productionOrders.create', absolute: false))->toBe('/tillverkning/tillverkningsorder/ny');

    $this->get('/tillverkning/tillverkningsorder')->assertOk()->assertSee('TO-1')->assertDontSee('TO-2')->assertSee('Ny tillverkningsorder');
    $this->get('/tillverkning/tillverkningsorder?status=fardiga')->assertOk()->assertSee('TO-2')->assertDontSee('TO-1');
    $this->get('/tillverkning/tillverkningsorder?status=alla&sok=TO-2')->assertOk()->assertSee('TO-2')->assertDontSee('TO-1');
    $this->get("/tillverkning/tillverkningsorder/{$open->id}")->assertOk()->assertSee('Tillverkningsorder TO-1')->assertSee('Frisläpp');
    $this->get('/tillverkning/tillverkningsorder/ny')->assertOk();
});

it('creates an order from an article on the page and lands on it with a toast', function () {
    Livewire::test(CreateProductionOrderPage::class)
        ->set('form.article_id', (string) $this->fx['articles']['table']->id)
        ->set('form.quantity', '3')
        ->set('form.due_on', '2026-11-30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('manufacturing.productionOrders.show', ProductionOrder::query()->sole()->id));

    $order = ProductionOrder::query()->sole();
    expect($order->quantity_milli)->toBe(3_000)
        ->and($order->due_on->toDateString())->toBe('2026-11-30')
        ->and(session('toasts')[0]['message'])->toBe('Tillverkningsorder TO-1 är skapad som utkast. Frisläpp den när den ska göras.');

    Livewire::test(CreateProductionOrderPage::class)
        ->set('form.article_id', (string) $this->fx['articles']['leg']->id)
        ->set('form.quantity', '1')
        ->call('save')
        ->assertHasErrors('form.article_id')
        ->assertDispatched('toast', type: 'error');
});

it('creates an order from a customer order row, linked from the order\'s panel', function () {
    $sales = Manufacturing::document($this->company, $this->owner, [['BORD-180', '4', '12000,00', 'Ekbord 180']], SalesDocument::ORDER);
    $key = $sales->rows[0]->line_key;

    Livewire::test(RowStructuresPanel::class, ['documentId' => $sales->id])
        ->assertSee('Skapa tillverkningsorder')
        ->assertSeeHtml(e(route('manufacturing.productionOrders.create', ['kundorder' => $sales->id, 'rad' => $key])));

    Livewire::withQueryParams(['kundorder' => $sales->id, 'rad' => $key])
        ->test(CreateProductionOrderPage::class)
        ->assertSet('form.source', 'kundorder')
        ->assertSee('Order '.$sales->number)
        ->call('save')
        ->assertHasNoErrors();

    $order = ProductionOrder::query()->sole();
    expect($order->sales_document_id)->toBe($sales->id)
        ->and($order->quantity_milli)->toBe(4_000);

    Livewire::test(RowStructuresPanel::class, ['documentId' => $sales->id])->assertSee('TO-1');
});

it('runs the whole order from the page with a toast for each step', function () {
    $order = pagesOrder($this);

    $page = Livewire::test(ProductionOrderPage::class, ['orderId' => $order->id])
        ->call('release')
        ->assertDispatched('toast', message: 'Ordern är frisläppt. Komponenterna är reserverade i inköpsförslaget.')
        ->set('starting', true)
        ->set('issueOnStart', false)
        ->call('start')
        ->assertDispatched('toast', message: 'Ordern är startad.')
        ->call('issue')
        ->assertDispatched('toast', message: 'Komponenterna är uttagna ur lagret till ordern.')
        ->call('openFinish')
        ->assertSet('finish.produced', '2');

    $assembly = $order->operations->firstWhere('operation_id', $this->fx['operations']['assembly']->id);

    $page->set("finish.minutes.{$assembly->id}", '200')
        ->call('finishOrder')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'TO-1 är färdig. Produkterna är mottagna i lagret till tillverkningskostnaden.')
        ->assertSee('Tillverkningskostnad');

    $order->refresh();
    expect($order->status)->toBe(ProductionOrderStatus::Finished)
        ->and($order->unit_cost_ore)->toBe(521_012)
        ->and(Inventory::level($this->fx['articles']['table'], $this->places['bin2']))->toBe(2_000);
});

it('changes the details and the order\'s own copy from the page', function () {
    $order = pagesOrder($this);

    Livewire::test(ProductionOrderPage::class, ['orderId' => $order->id])
        ->call('edit')
        ->set('form.quantity', '5')
        ->set('form.notes', 'Oljad yta')
        ->call('saveDetails')
        ->assertDispatched('toast', message: 'Tillverkningsordern är sparad.')
        ->call('editStructure')
        ->assertSet('structure.rows.3.unit', 'dl')
        ->assertSet('structure.rows.4.type', 'operation')
        ->call('removeRow', 2)
        ->call('saveStructure')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Orderns stycklista är sparad. Stycklistan för produkten är oförändrad.');

    $order->refresh()->load('components');
    expect($order->quantity_milli)->toBe(5_000)
        ->and($order->notes)->toBe('Oljad yta')
        ->and($order->components)->toHaveCount(3)
        ->and($this->fx['bom']->rows()->count())->toBe(7);
});

it('cancels a started order on the page only with a reason', function () {
    $order = pagesOrder($this);
    $life = app(ProductionOrderLifecycle::class);
    $life->release($this->owner, $this->company, $order);
    $life->start($this->owner, $this->company, $order, null, true);

    Livewire::test(ProductionOrderPage::class, ['orderId' => $order->id])
        ->set('cancelling', true)
        ->call('cancelOrder')
        ->assertDispatched('toast', type: 'error')
        ->set('cancelReason', 'Fel mått')
        ->call('cancelOrder')
        ->assertDispatched('toast', message: 'Ordern är makulerad. Det som var uttaget är tillbaka i lagret.');

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Cancelled);
});

it('shows the orders read-only to view and refuses everything else', function () {
    $order = pagesOrder($this);
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $this->company->unsetRelation('users');
    $this->actingAs($auditor);

    $this->get('/tillverkning/tillverkningsorder')->assertOk()->assertDontSee('Ny tillverkningsorder');
    $this->get("/tillverkning/tillverkningsorder/{$order->id}")->assertOk()->assertDontSee('Frisläpp');
    $this->get('/tillverkning/tillverkningsorder/ny')->assertForbidden();

    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->company->unsetRelation('users');
    $this->actingAs($bookkeeper);
    $this->get('/tillverkning/tillverkningsorder')->assertForbidden();

    $this->actingAs($this->owner);
    app(ModuleManager::class)->disable(ManufacturingModule::KEY, $this->company, $this->owner, confirmed: true);
    $this->get('/tillverkning/tillverkningsorder')->assertNotFound();
    $this->get("/tillverkning/tillverkningsorder/{$order->id}")->assertNotFound();
});

it('saves whether overhead is in the stock value and the work in progress accounts', function () {
    Livewire::test(ManufacturingSettingsForm::class, ['company' => $this->company])
        ->assertSet('wipStockAccount', '1440')
        ->assertSet('wipChangeAccount', '4940')
        ->assertSet('overheadInStock', false)
        ->set('overheadInStock', true)
        ->set('wipStockAccount', '1449')
        ->call('save')
        ->assertDispatched('toast', message: 'Inställningarna för tillverkning är sparade.')
        ->set('wipChangeAccount', '3001')
        ->call('save')
        ->assertHasErrors('wipChangeAccount');

    expect(ManufacturingSettings::overheadInStockValue($this->company))->toBeTrue()
        ->and(ManufacturingSettings::wipAccounts($this->company))->toBe([1449, 4940]);
});

it('runs the list and the order page in the same number of queries for few and many', function () {
    $count = function (Closure $render): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $orders = function (int $count): void {
        foreach (range(1, $count) as $index) {
            $sales = Manufacturing::document($this->company, $this->owner, [['BORD-180', '1', '12000,00', 'Ekbord 180']], SalesDocument::ORDER);
            app(SaveProductionOrder::class)->create($this->owner, $this->company, ['sales_document_id' => $sales->id, 'line_key' => $sales->rows[0]->line_key]);
        }
    };

    $wide = function (int $count): ProductionOrder {
        $rows = [];

        foreach (range(1, $count) as $index) {
            $part = Inventory::article($this->company, $this->owner, "DEL-{$count}-{$index}", "Del {$index}", ['purchase_price' => '10,00']);
            $rows[] = ['article_id' => $part->id, 'quantity' => '1'];
            $rows[] = ['operation_id' => $this->fx['operations']['assembly']->id, 'minutes' => '5'];
        }

        $product = Inventory::article($this->company, $this->owner, "PROD-{$count}", "Produkt {$count}");
        app(SaveBillOfMaterials::class)($this->owner, $this->company, $product, ['name' => "Produkt {$count}", 'yield' => '1', 'rows' => $rows]);
        $order = app(SaveProductionOrder::class)->create($this->owner, $this->company, ['article_id' => $product->id, 'quantity' => '2']);
        $life = app(ProductionOrderLifecycle::class);
        $life->release($this->owner, $this->company, $order);
        $life->start($this->owner, $this->company, $order);

        return $order;
    };

    $orders(2);
    $smallOrder = $wide(2);
    // Warm up once, so a first-render lookup is not counted against the small case.
    Livewire::test(ProductionOrdersPage::class);
    Livewire::test(ProductionOrderPage::class, ['orderId' => $smallOrder->id]);
    $small = [
        $count(fn () => Livewire::test(ProductionOrdersPage::class)),
        $count(fn () => Livewire::test(ProductionOrderPage::class, ['orderId' => $smallOrder->id])),
    ];

    $orders(15);
    $largeOrder = $wide(20);
    $large = [
        $count(fn () => Livewire::test(ProductionOrdersPage::class)),
        $count(fn () => Livewire::test(ProductionOrderPage::class, ['orderId' => $largeOrder->id])),
    ];

    expect($large)->toBe($small);
});
