<?php

declare(strict_types=1);

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Models\Supplier;
use App\Modules\Inventory\Domain\PlanningMethod;
use App\Modules\Inventory\Domain\PurchaseOrderSource;
use App\Modules\Inventory\Domain\PurchaseOrderStatus;
use App\Modules\Inventory\Models\DemandForecast;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Mrp\Actions\ConvertPlannedOrders;
use App\Modules\Manufacturing\Mrp\Actions\RunMrp;
use App\Modules\Manufacturing\Mrp\Livewire\MrpPage;
use App\Modules\Manufacturing\Mrp\Models\MrpPlannedOrder;
use App\Modules\Manufacturing\Mrp\Models\MrpRun;
use App\Modules\Manufacturing\Mrp\MrpSnapshot;
use App\Modules\Manufacturing\Mrp\PlannedOrderException;
use App\Modules\Manufacturing\Mrp\PlannedOrderKind;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Manufacturing;
use Tests\Support\Purchasing;

/*
 * Behovsplanering end to end (ADR-081 gap 5, E16): the snapshot reads the
 * company's orders, forecast, stock and bills of materials; a run stores
 * planned orders that a person turns into draft purchase and production
 * orders through the existing paths; nothing is created by the run itself.
 *
 * The joinery of Tests\Support\Manufacturing, today Wednesday 2026-11-18:
 * the oak table (made, lead time 7 days) and its legs (bought, lead time 5
 * days, 4 per table, 3 in stock, 5 more on order for 27 November).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->table = $this->fx['articles']['table'];
    $this->leg = $this->fx['articles']['leg'];
    $this->supplier = Purchasing::supplier($this->owner, $this->company);

    $this->table->forceFill(['is_stock_item' => true, 'planning_method' => PlanningMethod::Mrp, 'lead_time_days' => 7])->save();
    $this->leg->forceFill(['planning_method' => PlanningMethod::Mrp, 'lead_time_days' => 5, 'default_supplier_id' => $this->supplier->id])->save();
    Inventory::receive($this->company, $this->owner, $this->leg, $this->places['bin1'], '3', '350,00', '2026-11-01');
    Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $this->leg->id, 'quantity' => '5', 'unit_id' => $this->leg->stock_unit_id, 'expected_on' => '2026-11-27']]);

    // Two tables for a customer on 9 December, and a forecast of one table in the week of 14 December.
    $order = Manufacturing::document($this->company, $this->owner, [['BORD-180', '2', '12000,00']], SalesDocument::ORDER);
    $order->forceFill(['until_date' => '2026-12-09'])->save();
    $this->customerOrder = $order;
    DemandForecast::query()->create(['article_id' => $this->table->id, 'period_kind' => 'WEEK', 'period_start' => '2026-12-14', 'quantity_milli' => 1000, 'source' => 'MANUAL']);
});

function e16Run(object $test, int $weeks = 13): MrpRun
{
    return app(RunMrp::class)($test->owner, $test->company, $weeks);
}

/** @return list<MrpPlannedOrder> */
function e16Planned(MrpRun $run, Article $article): array
{
    return array_values(MrpPlannedOrder::query()->where('mrp_run_id', $run->id)->where('article_id', $article->id)->orderBy('due_on')->get()->all());
}

it('reads orders, forecast, stock, purchase orders and the bill of materials into the snapshot', function () {
    $input = app(MrpSnapshot::class)->read($this->company, CarbonImmutable::today(), 13);

    expect(array_keys($input->articles))->toEqualCanonicalizing([$this->table->id, $this->leg->id])
        ->and($input->articles[$this->table->id]->kind)->toBe(PlannedOrderKind::Production)
        ->and($input->articles[$this->table->id]->bom['yield_milli'])->toBe(1000)
        ->and($input->articles[$this->leg->id]->kind)->toBe(PlannedOrderKind::Purchase)
        ->and($input->articles[$this->leg->id]->onHandMilli)->toBe(3000)
        ->and($input->firstMonday->toDateString())->toBe('2026-11-16')
        ->and($input->forecast[$this->table->id]['2026-12-14'])->toBe(1000)
        ->and($input->receipts)->toHaveCount(1)
        ->and($input->receipts[0]->date)->toBe('2026-11-27')
        ->and($input->demands)->toHaveCount(1)
        ->and($input->demands[0]->date)->toBe('2026-12-09')
        ->and($input->demands[0]->number)->toBe($this->customerOrder->number);
});

it('plans the tables and, through the bill of materials, the legs that are missing', function () {
    $run = e16Run($this);

    $tables = e16Planned($run, $this->table);
    $legs = e16Planned($run, $this->leg);

    // Tables: 2 due 7 Dec (released 30 Nov), and the forecast's 1 due 14 Dec (released 7 Dec).
    expect(array_map(fn (MrpPlannedOrder $order): array => [$order->kind, $order->quantity_milli, $order->release_on->toDateString(), $order->due_on->toDateString()], $tables))->toBe([
        [PlannedOrderKind::Production, 2000, '2026-11-30', '2026-12-07'],
        [PlannedOrderKind::Production, 1000, '2026-12-07', '2026-12-14'],
    ])
        ->and($tables[0]->pegging[0])->toMatchArray(['kind' => 'CUSTOMER_ORDER', 'number' => $this->customerOrder->number, 'quantity_milli' => 2000])
        ->and($tables[1]->pegging[0])->toMatchArray(['kind' => 'FORECAST', 'quantity_milli' => 1000]);

    // Legs: 8 on 30 Nov less 3 in stock and 5 on order → none; 4 on 7 Dec → 4, released 2 Dec, from the default supplier.
    expect(array_map(fn (MrpPlannedOrder $order): array => [$order->kind, $order->quantity_milli, $order->release_on->toDateString(), $order->due_on->toDateString(), $order->supplier_id], $legs))->toBe([
        [PlannedOrderKind::Purchase, 4000, '2026-12-02', '2026-12-07', $this->supplier->id],
    ])
        ->and($legs[0]->pegging[0])->toMatchArray(['kind' => 'PLANNED_PARENT', 'article_id' => $this->table->id]);

    expect($run->planned_order_count)->toBe(3)
        ->and($run->article_count)->toBe(2)
        ->and($run->horizon_weeks)->toBe(13)
        ->and($run->starts_on->toDateString())->toBe('2026-11-16')
        ->and($run->inputs_hash)->toHaveLength(64)
        ->and($run->engine_version)->toBe('1');
});

it('creates nothing but the run: no purchase or production order is made by planning', function () {
    $before = [PurchaseOrder::query()->count(), ProductionOrder::query()->count()];

    e16Run($this);

    expect([PurchaseOrder::query()->count(), ProductionOrder::query()->count()])->toBe($before);
});

it('plans a made article as a purchase while Tillverkning is off', function () {
    app(ModuleManager::class)->disable(ManufacturingModule::KEY, $this->company, $this->owner, confirmed: true);

    $run = e16Run($this);

    expect(array_map(fn (MrpPlannedOrder $order): PlannedOrderKind => $order->kind, e16Planned($run, $this->table)))->toBe([PlannedOrderKind::Purchase, PlannedOrderKind::Purchase])
        ->and(e16Planned($run, $this->leg))->toBe([]);
});

it('counts open production orders as receipts and their components as demand', function () {
    app(SaveProductionOrder::class)->create($this->owner, $this->company, ['article_id' => $this->table->id, 'quantity' => '3', 'planned_start_on' => '2026-11-23', 'due_on' => '2026-12-04']);

    $run = e16Run($this);

    // Three tables are coming on 4 Dec and cover the order and the forecast; their 12 legs are needed from 23 Nov,
    // against 3 in stock and 5 on order that week.
    expect(e16Planned($run, $this->table))->toBe([])
        ->and(array_map(fn (MrpPlannedOrder $order): array => [$order->quantity_milli, $order->due_on->toDateString(), $order->exception], e16Planned($run, $this->leg)))->toBe([
            [4000, '2026-11-23', PlannedOrderException::ReleaseNow],
        ])
        ->and(e16Planned($run, $this->leg)[0]->pegging[0])->toMatchArray(['kind' => 'PRODUCTION_ORDER', 'quantity_milli' => 4000]);
});

it('marks a planned order whose release date has passed as late', function () {
    $this->leg->forceFill(['lead_time_days' => 30])->save();

    $run = e16Run($this);
    [$legs] = e16Planned($run, $this->leg);

    expect($legs->exception)->toBe(PlannedOrderException::Late)
        ->and($legs->release_on->toDateString())->toBe('2026-11-18')
        ->and($run->exception_count)->toBeGreaterThanOrEqual(1);
});

it('refuses a horizon outside 1 to 52 weeks and plans 52 weeks at most', function () {
    expect(fn () => e16Run($this, 0))->toThrow(ValidationException::class)
        ->and(fn () => e16Run($this, 53))->toThrow(ValidationException::class)
        ->and(e16Run($this, 52)->horizon_weeks)->toBe(52);
});

it('writes the audit log with counts only and deletes runs older than 30 days', function () {
    $old = MrpRun::create(['starts_on' => '2026-10-12', 'horizon_weeks' => 13, 'inputs_hash' => str_repeat('0', 64), 'engine_version' => '1']);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    MrpPlannedOrder::create(['mrp_run_id' => $old->id, 'article_id' => $this->leg->id, 'kind' => 'PURCHASE', 'quantity_milli' => 1000, 'release_on' => '2026-10-12', 'due_on' => '2026-10-12']);

    $run = e16Run($this);
    $audit = AuditLog::query()->where('action', 'mrp.run')->sole();

    expect(MrpRun::query()->pluck('id')->all())->toBe([$run->id])
        ->and(MrpPlannedOrder::query()->where('mrp_run_id', $old->id)->count())->toBe(0)
        ->and(array_keys($audit->payload))->toEqualCanonicalizing(['horizon_weeks', 'starts_on', 'articles', 'planned_orders', 'exceptions', 'messages', 'engine_version'])
        ->and(json_encode($audit->payload))->not->toContain('Ekbord');
});

it('prunes old runs nightly, company by company', function () {
    $old = MrpRun::create(['starts_on' => '2026-10-12', 'horizon_weeks' => 13, 'inputs_hash' => str_repeat('0', 64), 'engine_version' => '1']);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    $kept = e16Run($this);

    $this->artisan('mrp:prune')->assertSuccessful();

    expect(MrpRun::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('lets those who may view see the plan, and only those who may run it run it', function () {
    e16Run($this);
    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(RunMrp::class)($auditor, $this->company))->toThrow(AuthorizationException::class);

    $this->actingAs($auditor);
    Livewire::test(MrpPage::class)
        ->assertSee('Ekbord 180')
        ->assertDontSee('Kör behovsplanering')
        ->assertDontSee('Skapa utkast av de valda')
        ->call('run')
        ->assertForbidden();

    $this->actingAs(Bookkeeping::member($this->company, 'stock_clerk'));
    $this->get(route('inventory.mrp'))->assertForbidden();
});

it('turns the chosen planned orders into drafts through the existing paths', function () {
    $run = e16Run($this);
    $ids = MrpPlannedOrder::query()->where('mrp_run_id', $run->id)->orderBy('id')->pluck('id')->all();

    $made = app(ConvertPlannedOrders::class)($this->owner, $this->company, $ids);

    expect($made['purchase_orders'])->toHaveCount(1)
        ->and($made['production_orders'])->toHaveCount(2);

    $purchase = $made['purchase_orders'][0]->fresh(['rows']);

    expect($purchase->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($purchase->source)->toBe(PurchaseOrderSource::Mrp)
        ->and($purchase->supplier_id)->toBe($this->supplier->id)
        ->and($purchase->rows->map(fn ($row): array => [$row->quantity_milli, $row->expected_on?->toDateString()])->all())->toBe([[4000, '2026-12-07']])
        ->and(collect($made['production_orders'])->map(fn (ProductionOrder $order): array => [$order->status, $order->quantity_milli, $order->planned_start_on?->toDateString(), $order->due_on?->toDateString()])->all())->toBe([
            [ProductionOrderStatus::Draft, 2000, '2026-11-30', '2026-12-07'],
            [ProductionOrderStatus::Draft, 1000, '2026-12-07', '2026-12-14'],
        ])
        ->and(MrpPlannedOrder::query()->whereNull('converted_type')->count())->toBe(0)
        ->and(MrpPlannedOrder::query()->where('converted_type', 'purchase_order')->value('converted_id'))->toBe($purchase->id);

    $audit = AuditLog::query()->where('action', 'mrp.planned_orders_converted')->sole();
    expect(array_keys($audit->payload))->toEqualCanonicalizing(['mrp_run_id', 'planned_order_ids', 'purchase_order_ids', 'production_order_ids']);

    // The drafts now count: planning again proposes nothing new.
    expect(e16Run($this)->planned_order_count)->toBe(0);
});

it('expects a late planned order the lead time from today, not on the date it is needed', function () {
    $this->leg->forceFill(['lead_time_days' => 30])->save();
    $run = e16Run($this);
    [$legs] = e16Planned($run, $this->leg);

    $made = app(ConvertPlannedOrders::class)($this->owner, $this->company, [$legs->id]);

    expect($made['purchase_orders'][0]->fresh(['rows'])->rows->first()->expected_on->toDateString())->toBe('2026-12-18');
});

it('refuses planned orders of an older run, ones already converted, and an empty choice', function () {
    $first = e16Run($this);
    $old = MrpPlannedOrder::query()->where('mrp_run_id', $first->id)->value('id');
    $latest = e16Run($this);
    $current = MrpPlannedOrder::query()->where('mrp_run_id', $latest->id)->where('kind', 'PURCHASE')->value('id');

    expect(fn () => app(ConvertPlannedOrders::class)($this->owner, $this->company, [$old]))->toThrow(ValidationException::class)
        ->and(fn () => app(ConvertPlannedOrders::class)($this->owner, $this->company, []))->toThrow(ValidationException::class);

    app(ConvertPlannedOrders::class)($this->owner, $this->company, [$current]);

    expect(fn () => app(ConvertPlannedOrders::class)($this->owner, $this->company, [$current]))->toThrow(ValidationException::class)
        ->and(PurchaseOrder::query()->where('source', 'MRP')->count())->toBe(1);
});

it('needs the right to make the order kind as well: a bookkeeper buys but does not make', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $run = app(RunMrp::class)($bookkeeper, $this->company);
    $purchase = MrpPlannedOrder::query()->where('mrp_run_id', $run->id)->where('kind', 'PURCHASE')->pluck('id')->all();
    $production = MrpPlannedOrder::query()->where('mrp_run_id', $run->id)->where('kind', 'PRODUCTION')->pluck('id')->all();

    expect(fn () => app(ConvertPlannedOrders::class)($bookkeeper, $this->company, $production))->toThrow(AuthorizationException::class)
        ->and(ProductionOrder::query()->count())->toBe(0);

    app(ConvertPlannedOrders::class)($bookkeeper, $this->company, $purchase);

    expect(PurchaseOrder::query()->where('source', 'MRP')->count())->toBe(1);
});

it('leaves the purchase proposal as it was', function () {
    $lines = app(PurchaseProposal::class)->lines($this->company);
    $table = collect($lines)->first(fn ($line): bool => $line->article->id === $this->table->id);

    expect($table->reservedMilli)->toBe(2000)
        ->and($table->reservedBy)->toBe(['Öppna kundordrar' => 2000]);
});

it('runs and converts from the page with toasts', function () {
    $component = Livewire::test(MrpPage::class)
        ->assertSee('Ingen behovsplanering ännu')
        ->set('weeks', '8')
        ->call('run')
        ->assertDispatched('toast', message: 'Behovsplaneringen är klar: 3 planerade order, varav 0 att åtgärda, och 0 råd om befintliga order.')
        ->assertSee('Ekbord 180')
        ->assertSee('Kundorder '.$this->customerOrder->number);

    $ids = MrpPlannedOrder::query()->where('kind', 'PURCHASE')->pluck('id')->all();

    $component->set('selected', $ids)
        ->call('convert')
        ->assertDispatched('toast', message: 'Utkast skapade: 1 inköpsorder och 0 tillverkningsorder. Granska och skicka eller släpp dem som vanligt.')
        ->assertSee('Inköpsorder '.PurchaseOrder::query()->where('source', 'MRP')->value('number'))
        ->call('convert')
        ->assertDispatched('toast', type: 'error');

    expect(MrpRun::query()->sole()->horizon_weeks)->toBe(8);
});

it('clamps the weeks on the page to 1–52 with 13 by default', function () {
    Livewire::test(MrpPage::class)
        ->assertSet('weeks', '13')
        ->set('weeks', '500')->assertSet('weeks', '52')
        ->set('weeks', '0')->assertSet('weeks', '13');
});

it('answers on its Swedish address and filters the planned orders', function () {
    e16Run($this);

    $this->get('/lager/behovsplanering')->assertOk()->assertSee('Behovsplanering');

    $page = Livewire::test(MrpPage::class);
    expect($page->viewData('orders')->total())->toBe(3);

    $page->set('kind', 'inkop')->assertSee('Bordsben ek');
    expect(collect($page->viewData('orders')->items())->map(fn (MrpPlannedOrder $order): PlannedOrderKind => $order->kind)->unique()->all())->toBe([PlannedOrderKind::Purchase]);

    $page->set('kind', 'tillverkning')->assertSee('Ekbord 180');
    expect($page->viewData('orders')->total())->toBe(2);

    $page->set('kind', '')->set('exceptionsOnly', true);
    expect($page->viewData('orders')->total())->toBe(0);
});

it('runs the planning and shows the page with the same number of queries for a small and a large plan', function () {
    $runQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        e16Run($this);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $pageQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MrpPage::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $grow = function (int $from, int $to): void {
        foreach (range($from, $to) as $index) {
            $article = Inventory::article($this->company, $this->owner, "MRP-{$index}", "Artikel {$index}", ['planning_method' => 'MRP', 'lead_time_days' => '3', 'safety_stock' => '2', 'default_supplier_id' => $this->supplier->id]);
            DemandForecast::query()->create(['article_id' => $article->id, 'period_kind' => 'WEEK', 'period_start' => '2026-11-30', 'quantity_milli' => 5000, 'source' => 'MANUAL']);
            Purchasing::order($this->owner, $this->company, $this->supplier, [['article_id' => $article->id, 'quantity' => '1', 'unit_id' => $article->stock_unit_id, 'expected_on' => '2026-12-21']]);

            // A made article with its own bill of materials (of the bought one), a customer order row and an open production order.
            $made = Inventory::article($this->company, $this->owner, "TILL-{$index}", "Tillverkad {$index}", ['planning_method' => 'MRP', 'lead_time_days' => '5']);
            app(SaveBillOfMaterials::class)($this->owner, $this->company, $made, ['name' => "Tillverkad {$index}", 'yield' => '1', 'rows' => [['article_id' => $article->id, 'quantity' => '2']]]);
            $order = Manufacturing::document($this->company, $this->owner, [["TILL-{$index}", '3', '100,00']], SalesDocument::ORDER);
            $order->forceFill(['until_date' => '2026-12-16'])->save();
            app(SaveProductionOrder::class)->create($this->owner, $this->company, ['article_id' => $made->id, 'quantity' => '1', 'planned_start_on' => '2026-11-30', 'due_on' => '2026-12-04']);
        }
    };

    $grow(1, 1);
    $runQueries();
    $pageQueries();
    $smallRun = $runQueries();
    $smallPage = $pageQueries();
    $grow(2, 12);

    // Both plans stay under 500 planned orders; RunMrp inserts them in chunks of 500, one query per chunk.
    expect(MrpRun::query()->latest('id')->value('planned_order_count'))->toBeLessThan(500)
        ->and($runQueries())->toBe($smallRun)
        ->and($pageQueries())->toBe($smallPage);
});

it('marks converted planned orders with one update per order kind, however many there are', function () {
    $updates = function (array $ids): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ConvertPlannedOrders::class)($this->owner, $this->company, $ids);
        // Every statement that writes planned orders: an update, or the upsert that marks them.
        $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/^(update|insert into) "mrp_planned_orders"/i', $query['query']) === 1)->count();
        DB::disableQueryLog();

        return $count;
    };

    foreach (range(1, 6) as $index) {
        $article = Inventory::article($this->company, $this->owner, "KOP-{$index}", "Köpt {$index}", ['planning_method' => 'MRP', 'default_supplier_id' => $this->supplier->id]);
        DemandForecast::query()->create(['article_id' => $article->id, 'period_kind' => 'WEEK', 'period_start' => '2026-11-30', 'quantity_milli' => 1000, 'source' => 'MANUAL']);
    }

    $run = e16Run($this);
    $purchase = MrpPlannedOrder::query()->where('mrp_run_id', $run->id)->where('kind', 'PURCHASE')->orderBy('id')->pluck('id')->all();
    $production = MrpPlannedOrder::query()->where('mrp_run_id', $run->id)->where('kind', 'PRODUCTION')->pluck('id')->all();

    expect(count($purchase))->toBeGreaterThan(5)
        ->and($updates([$purchase[0]]))->toBe(1)
        ->and($updates(array_slice($purchase, 1)))->toBe(1)
        ->and($updates($production))->toBe(1)
        ->and(MrpPlannedOrder::query()->whereNull('converted_id')->count())->toBe(0)
        ->and(MrpPlannedOrder::query()->where('kind', 'PRODUCTION')->pluck('converted_id')->sort()->values()->all())->toBe(ProductionOrder::query()->orderBy('id')->pluck('id')->all());
});
