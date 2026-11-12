<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\SalesDocument;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Purchasing\PurchaseProposal;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Actions\SaveRowStructure;
use App\Modules\Manufacturing\Domain\LabourSource;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderIssue;
use App\Modules\ModuleDisabledException;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Manufacturing;

/*
 * Production orders (ADR-071 §8): the copy of the bill of materials, the
 * status machine, issues at the components' valuation cost, the finished
 * goods at the manufacturing cost (fixtures F-M1–F-M6 of
 * tests/Fixtures/manufacturing/production-orders.json), cancellation, the
 * purchase proposal, and the permissions.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->fixtures = Manufacturing::productionFixtures();
    $this->save = app(SaveProductionOrder::class);
    $this->life = app(ProductionOrderLifecycle::class);
});

function productionOrder(object $test, string $quantity = '2', array $extra = []): ProductionOrder
{
    return $test->save->create($test->owner, $test->company, ['article_id' => $test->fx['articles']['table']->id, 'quantity' => $quantity, 'location_id' => $test->places['bin2']->id, ...$extra]);
}

it('copies the bill of materials valid today into a new draft with the needs of the whole order', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this, '2', ['planned_start_on' => '2026-11-16', 'due_on' => '2026-11-24']);
    $a = $this->fx['articles'];

    expect($order->status)->toBe(ProductionOrderStatus::Draft)
        ->and($order->reference())->toBe('TO-1')
        ->and($order->quantity_milli)->toBe(2_000)
        ->and($order->bill_of_materials_id)->toBe($this->fx['bom']->id)
        ->and($order->components->pluck('required_milli', 'component_article_id')->all())->toBe([
            $a['top']->id => $this->fixtures['required_milli']['top'],
            $a['leg']->id => $this->fixtures['required_milli']['leg'],
            $a['screw']->id => $this->fixtures['required_milli']['screw'],
            $a['lacquer']->id => $this->fixtures['required_milli']['lacquer'],
        ])
        ->and($order->operations->pluck('planned_minutes')->all())->toBe(array_values($this->fixtures['planned_minutes']))
        ->and($order->components[3]->entered_unit)->toBe('dl')
        ->and($order->planned_start_on->toDateString())->toBe('2026-11-16');

    expect(productionOrder($this)->number)->toBe(2);

    $audit = AuditLog::query()->where('action', 'manufacturing.production_order_created')->latest('id')->first();
    expect($audit?->payload)->toMatchArray(['article_id' => $a['table']->id, 'quantity_milli' => 2_000, 'source' => 'article', 'components' => 4, 'operations' => 3]);
});

it('takes the quantity and the row\'s own copy from a customer order row', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = Manufacturing::document($this->company, $this->owner, [['BORD-180', '3', '12000,00', 'Ekbord 180']], SalesDocument::ORDER);
    $key = $order->rows[0]->line_key;
    $rows = app(SaveRowStructure::class);
    $rows->choose($this->owner, $order, $key, $this->fx['articles']['table']);
    $rows->update($this->owner, $order, $key, ['yield' => '1', 'lines' => [
        ['article_id' => $this->fx['articles']['top']->id, 'quantity' => '1,62'],
        ['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '6'],
        ['operation_id' => $this->fx['operations']['assembly']->id, 'minutes' => '100'],
    ]]);

    $production = $this->save->create($this->owner, $this->company, ['sales_document_id' => $order->id, 'line_key' => $key]);

    expect($production->quantity_milli)->toBe(3_000)
        ->and($production->sales_document_id)->toBe($order->id)
        ->and($production->sales_line_key)->toBe($key)
        ->and($production->components->pluck('required_milli')->all())->toBe([4_860, 18_000])
        ->and($production->operations->pluck('planned_minutes')->all())->toBe([300]);
});

it('converts the quantity from another unit to the stock unit', function () {
    $top = $this->fx['articles']['top'];
    app(SaveBillOfMaterials::class)($this->owner, $this->company, $top, ['name' => 'Ekskiva', 'yield' => '1', 'rows' => [['article_id' => $this->fx['articles']['lacquer']->id, 'quantity' => '1']]]);

    $order = $this->save->create($this->owner, $this->company, ['article_id' => $top->id, 'quantity' => '2', 'unit' => 'st']);

    // F-U2: one board is 2,977 m².
    expect($order->quantity_milli)->toBe(5_954)
        ->and($order->entered_quantity)->toBe('2')
        ->and($order->components[0]->required_milli)->toBe(5_954);
});

it('refuses an article that is not kept in stock or has no bill of materials', function () {
    expect(fn () => productionOrder($this))->toThrow(ValidationException::class, 'inte lagerförd');

    $this->fx['articles']['leg']->forceFill(['is_stock_item' => true])->save();
    expect(fn () => $this->save->create($this->owner, $this->company, ['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '1']))
        ->toThrow(ValidationException::class, 'ingen stycklista');
});

it('moves through the statuses and refuses a step that is not allowed', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this);

    expect(fn () => $this->life->start($this->owner, $this->company, $order))->toThrow(ValidationException::class, 'utkast och kan inte bli startad')
        ->and(fn () => $this->life->finish($this->owner, $this->company, $order))->toThrow(ValidationException::class);

    $this->life->release($this->owner, $this->company, $order);
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Released);

    $this->life->backToDraft($this->owner, $this->company, $order);
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Draft);

    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order);
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Started)
        ->and(ProductionOrderIssue::query()->count())->toBe(0)
        ->and(fn () => $this->life->backToDraft($this->owner, $this->company, $order))->toThrow(ValidationException::class);

    $this->life->finish($this->owner, $this->company, $order);
    $order->refresh();
    expect($order->status)->toBe(ProductionOrderStatus::Finished)
        ->and($order->finished_on->toDateString())->toBe('2026-11-10')
        ->and(fn () => $this->life->cancel($this->owner, $this->company, $order, 'x'))->toThrow(ValidationException::class);

    expect(AuditLog::query()->where('subject_id', (string) $order->id)->where('action', 'like', 'manufacturing.production_order_%')->orderBy('id')->pluck('action')->all())->toBe([
        'manufacturing.production_order_created',
        'manufacturing.production_order_released',
        'manufacturing.production_order_back_to_draft',
        'manufacturing.production_order_released',
        'manufacturing.production_order_started',
        'manufacturing.production_order_finished',
    ]);
});

it('changes the quantity and the copy only while the order is a draft', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this);

    $order = $this->save->update($this->owner, $this->company, $order, ['quantity' => '4']);
    expect($order->components->pluck('required_milli')->all())->toBe([6_480, 16_000, 64_000, 1_332])
        ->and($order->operations->pluck('planned_minutes')->all())->toBe([120, 360, 120]);

    $order = $this->save->saveStructure($this->owner, $this->company, $order, ['yield' => '2', 'lines' => [
        ['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '8'],
        ['operation_id' => $this->fx['operations']['assembly']->id, 'minutes' => '90'],
    ]]);
    expect($order->yield_milli)->toBe(2_000)
        ->and($order->components->pluck('required_milli')->all())->toBe([16_000])
        ->and($order->operations->pluck('planned_minutes')->all())->toBe([180]);

    $this->life->release($this->owner, $this->company, $order);

    expect(fn () => $this->save->update($this->owner, $this->company, $order, ['quantity' => '5']))->toThrow(ValidationException::class, 'utkast')
        ->and(fn () => $this->save->saveStructure($this->owner, $this->company, $order, ['yield' => '1', 'lines' => [['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '4']]]))->toThrow(ValidationException::class, 'utkast');

    $order = $this->save->update($this->owner, $this->company, $order, ['due_on' => '2026-12-01', 'notes' => 'Kunden hämtar']);
    expect($order->due_on->toDateString())->toBe('2026-12-01')
        ->and($order->notes)->toBe('Kunden hämtar')
        ->and($order->quantity_milli)->toBe(4_000);
});

it('costs the finished goods as the fixtures say', function (string $id) {
    $case = collect($this->fixtures['cases'])->firstWhere('id', $id);
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['valuation_method' => $case['method']]);

    if (isset($case['overhead_bp'])) {
        app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $this->company, $this->owner, ['overhead_bp' => $case['overhead_bp'], 'overhead_in_stock_value' => $case['overhead_in_stock_value']]);
    }

    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1'], ! isset($case['legs']));

    foreach ($case['legs'] ?? [] as $receipt) {
        Inventory::receive($this->company, $this->owner, $this->fx['articles']['leg'], $this->places['bin1'], $receipt['quantity'], $receipt['unit_cost'], $receipt['date']);
    }

    $order = productionOrder($this);
    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order, null, true);

    $issued = ProductionOrderIssue::query()->with('article:id,number')->get()->groupBy('article_id')->map->sum('value_ore');
    $a = $this->fx['articles'];

    foreach ($case['issued_value_ore'] ?? [] as $key => $value) {
        expect($issued[$a[$key]->id])->toBe($value, "{$id} {$key}");
    }

    $minutes = [];

    foreach ($case['minutes'] as $key => $value) {
        $operation = $order->operations->firstWhere('operation_id', $this->fx['operations'][$key]->id);
        $minutes[$operation->id] = $value;
    }

    $finished = $this->life->finish($this->owner, $this->company, $order, ['minutes' => $minutes]);

    expect($finished->material_cost_ore)->toBe($case['material_ore'])
        ->and($finished->labour_cost_ore)->toBe($case['labour_ore'])
        ->and($finished->overhead_cost_ore)->toBe($case['overhead_ore'])
        ->and($finished->unit_cost_ore)->toBe($case['unit_cost_ore'])
        ->and($finished->totalCostOre())->toBe($case['material_ore'] + $case['labour_ore'] + $case['overhead_ore']);

    $receipt = StockMovement::query()->where('type', MovementType::ProductionReceipt)->sole();
    expect($receipt->quantity_milli)->toBe(2_000)
        ->and($receipt->unit_cost_ore)->toBe($case['unit_cost_ore'])
        ->and($receipt->article_id)->toBe($a['table']->id)
        ->and($receipt->location_id)->toBe($this->places['bin2']->id)
        ->and(Inventory::level($a['table'], $this->places['bin2']))->toBe(2_000)
        ->and(Inventory::level($a['leg'], $this->places['bin1']))->toBe(isset($case['legs']) ? 12_000 : 12_000);
})->with(['F-M1', 'F-M2', 'F-M3', 'F-M4', 'F-M5', 'F-M6']);

it('records the planned minutes, or the minutes entered as the operation\'s total, as labour', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this);
    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order);
    [$cut, $assembly, $lacquering] = $order->operations->all();

    // As the workshop terminal (M5) will: 150 minutes of Montering reported before finishing.
    ProductionLabourEntry::create(['production_order_id' => $order->id, 'production_order_operation_id' => $assembly->id, 'source' => LabourSource::Terminal, 'minutes' => 150, 'hourly_cost_ore' => 48_000, 'cost_ore' => 120_000, 'worked_on' => '2026-11-10']);

    $this->life->finish($this->owner, $this->company, $order, ['minutes' => [$cut->id => '75']]);

    $entries = ProductionLabourEntry::query()->orderBy('id')->get();
    expect($entries->map(fn ($entry) => [$entry->production_order_operation_id, $entry->source, $entry->minutes, $entry->cost_ore])->all())->toBe([
        [$assembly->id, LabourSource::Terminal, 150, 120_000],
        [$cut->id, LabourSource::Entered, 75, 56_250],
        [$lacquering->id, LabourSource::Planned, 60, 52_000],
    ])
        ->and($order->fresh()->labour_cost_ore)->toBe(228_250);
});

it('takes components from the default location first, then the others, and refuses what is not on hand', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $leg = $this->fx['articles']['leg'];
    Inventory::receive($this->company, $this->owner, $leg, $this->places['bin2'], '5', '350,00', '2026-11-02');
    $leg->forceFill(['default_location_id' => $this->places['bin2']->id])->save();

    $order = productionOrder($this);
    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order, null, true);

    expect(ProductionOrderIssue::query()->where('article_id', $leg->id)->orderBy('id')->get()->map(fn ($issue) => [$issue->location_id, $issue->quantity_milli])->all())->toBe([
        [$this->places['bin2']->id, 5_000],
        [$this->places['bin1']->id, 3_000],
    ]);

    $big = productionOrder($this, '20');
    $this->life->release($this->owner, $this->company, $big);
    expect(fn () => $this->life->start($this->owner, $this->company, $big, null, true))->toThrow(ValidationException::class, 'Det finns bara');
    expect($big->fresh()->status)->toBe(ProductionOrderStatus::Released);
});

it('issues what remains while started, and says when everything is out', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this);
    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order);

    $this->life->issue($this->owner, $this->company, $order);
    expect($order->components()->get()->every(fn ($component) => $component->issued_milli === $component->required_milli))->toBeTrue()
        ->and(fn () => $this->life->issue($this->owner, $this->company, $order))->toThrow(ValidationException::class, 'redan uttaget');
});

it('puts the components back at the cost they left with when a started order is cancelled', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this);
    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order, null, true);

    expect(fn () => $this->life->cancel($this->owner, $this->company, $order))->toThrow(ValidationException::class, 'varför');

    $this->life->cancel($this->owner, $this->company, $order, 'Kunden avbeställde');

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Cancelled)
        ->and($order->fresh()->cancel_reason)->toBe('Kunden avbeställde')
        ->and((int) ProductionOrderIssue::query()->sum('value_ore'))->toBe(0)
        ->and((int) ProductionOrderIssue::query()->sum('quantity_milli'))->toBe(0)
        ->and(Inventory::level($this->fx['articles']['leg'], $this->places['bin1']))->toBe(20_000)
        ->and($order->components()->sum('issued_milli'))->toBe(0);

    $report = StockValuationReport::at($this->company, CarbonImmutable::today());
    expect($report->costValueOre)->toBe(2_415_000);
});

it('keeps issues and labour append-only in the database', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this);
    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order, null, true);
    $this->life->finish($this->owner, $this->company, $order);

    foreach (['production_order_issues', 'production_labour_entries'] as $table) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->update(['created_by' => null])))->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table($table)->delete()))->toThrow(QueryException::class, 'append-only');
    }

    expect(fn () => ProductionOrderIssue::query()->first()->update(['value_ore' => 1]))->toThrow(LogicException::class);
});

it('reserves the components of released and started orders, and counts the finished goods as coming, in the purchase proposal', function () {
    $a = $this->fx['articles'];
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $draft = productionOrder($this, '1');
    $released = productionOrder($this, '6');
    $this->life->release($this->owner, $this->company, $released);
    $started = productionOrder($this, '2');
    $this->life->release($this->owner, $this->company, $started);
    $this->life->start($this->owner, $this->company, $started);
    $this->life->issue($this->owner, $this->company, $started);

    // Legs: 20 on hand − 8 issued = 12; the released order needs 24, the draft reserves nothing.
    $lines = collect(app(PurchaseProposal::class)->lines($this->company))->keyBy(fn ($line) => $line->article->id);

    expect($lines[$a['leg']->id]->reservedMilli)->toBe(24_000)
        ->and($lines[$a['leg']->id]->reservedBy)->toBe(['Tillverkningsorder' => 24_000])
        ->and($lines[$a['leg']->id]->shortMilli)->toBe(12_000)
        ->and($lines->has($a['table']->id))->toBeFalse();

    // A customer order for the tables does not propose buying them: the orders make 8.
    Manufacturing::document($this->company, $this->owner, [['BORD-180', '5', '12000,00', 'Ekbord 180']], SalesDocument::ORDER);
    $lines = collect(app(PurchaseProposal::class)->lines($this->company))->keyBy(fn ($line) => $line->article->id);
    expect($lines->has($a['table']->id))->toBeFalse();
    expect($draft->fresh()->status)->toBe(ProductionOrderStatus::Draft);
});

it('lets owner and admin manage, accountant and auditor view, and nobody else', function () {
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $order = productionOrder($this);

    foreach (['accountant', 'auditor', 'bookkeeper', 'approver'] as $role) {
        $user = Bookkeeping::member($this->company, $role);
        $this->company->unsetRelation('users');
        expect(fn () => $this->life->release($user, $this->company, $order))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->save->create($user, $this->company, ['article_id' => $this->fx['articles']['table']->id, 'quantity' => '1']))->toThrow(AuthorizationException::class);
    }

    $admin = Bookkeeping::member($this->company, 'admin');
    $this->company->unsetRelation('users');
    $this->life->release($admin, $this->company, $order);
    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Released);

    app(ModuleManager::class)->disable(ManufacturingModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(fn () => $this->life->start($this->owner, $this->company->fresh(), $order))->toThrow(ModuleDisabledException::class);
});
