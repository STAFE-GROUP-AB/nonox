<?php

declare(strict_types=1);

use App\Models\FiscalYear;
use App\Modules\Inventory\Domain\MovementType;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Actions\SaveBillOfMaterials;
use App\Modules\Manufacturing\Domain\ProductionOrderStatus;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderIssue;
use App\Modules\Manufacturing\Production\WorkInProgress;
use App\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Inventory;
use Tests\Support\Manufacturing;

/*
 * The M4 review (ADR-071 §8): back-dated issues under FIFO, finish and
 * cancel dated before the order's own issues, exact value on a cancel,
 * cancel into an inactive location, the overhead fixed per order, and
 * dates in closed years and locked periods.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->life = app(ProductionOrderLifecycle::class);

    // A product made of legs only: 4 per unit, no operations.
    $this->stool = Inventory::article($this->company, $this->owner, 'PALL-1', 'Pall');
    app(SaveBillOfMaterials::class)($this->owner, $this->company, $this->stool, ['name' => 'Pall', 'yield' => '1', 'rows' => [['article_id' => $this->fx['articles']['leg']->id, 'quantity' => '4']]]);
});

function stoolOrder(object $test, string $quantity = '4'): ProductionOrder
{
    $order = app(SaveProductionOrder::class)->create($test->owner, $test->company, ['article_id' => $test->stool->id, 'quantity' => $quantity, 'location_id' => $test->places['bin2']->id]);
    $test->life->release($test->owner, $test->company, $order);

    return $order;
}

/** Stock plus work in progress on a day: never more or less than what came in, less what is sold. */
function stockAndWip(object $test, string $day): int
{
    $date = CarbonImmutable::parse($day);

    return StockValuationReport::at($test->company, $date)->costValueOre + WorkInProgress::at($test->company, $date)->valueOre;
}

it('P2-1: refuses a FIFO issue dated before a later movement of the component', function () {
    app(ModuleManager::class)->updateSettings(InventoryModule::KEY, $this->company, $this->owner, ['valuation_method' => 'FIFO']);
    $leg = $this->fx['articles']['leg'];
    Inventory::receive($this->company, $this->owner, $leg, $this->places['bin1'], '20', '350,00', '2026-11-01');
    Inventory::receive($this->company, $this->owner, $leg, $this->places['bin1'], '20', '500,00', '2026-11-05');

    $first = stoolOrder($this);
    $this->life->start($this->owner, $this->company, $first, null, true);
    expect((int) ProductionOrderIssue::query()->sum('value_ore'))->toBe(560_000);

    $second = stoolOrder($this);
    expect(fn () => $this->life->start($this->owner, $this->company, $second, '2026-11-06', true))->toThrow(ValidationException::class, 'senast ändrades i lagret');

    $this->life->start($this->owner, $this->company, $second, '2026-11-10', true);
    expect((int) ProductionOrderIssue::query()->where('production_order_id', $second->id)->sum('value_ore'))->toBe(740_000)
        ->and(stockAndWip($this, '2026-11-10'))->toBe(1_700_000);
});

it('P2-2: refuses to finish or cancel before the order\'s own issues or labour', function () {
    Inventory::receive($this->company, $this->owner, $this->fx['articles']['leg'], $this->places['bin1'], '40', '350,00', '2026-11-01');
    $order = stoolOrder($this);
    $this->life->start($this->owner, $this->company, $order, '2026-11-02');
    $this->life->issue($this->owner, $this->company, $order, '2026-11-10');

    expect(fn () => $this->life->finish($this->owner, $this->company, $order, ['date' => '2026-11-02']))->toThrow(ValidationException::class, 'före 2026-11-10')
        ->and(fn () => $this->life->cancel($this->owner, $this->company, $order, 'Fel', '2026-11-03'))->toThrow(ValidationException::class, 'före 2026-11-10');

    $this->life->finish($this->owner, $this->company, $order, ['date' => '2026-11-10']);
    expect(stockAndWip($this, '2026-11-03'))->toBe(1_400_000)
        ->and(stockAndWip($this, '2026-11-10'))->toBe(1_400_000);
});

it('P3-1: a cancel puts back exactly the value that left', function () {
    $leg = $this->fx['articles']['leg'];
    Inventory::receive($this->company, $this->owner, $leg, $this->places['bin1'], '3', '100,00', '2026-11-01');
    Inventory::receive($this->company, $this->owner, $leg, $this->places['bin1'], '3', '100,01', '2026-11-02');
    Inventory::receive($this->company, $this->owner, $leg, $this->places['bin1'], '1', '100,01', '2026-11-03');
    $before = stockAndWip($this, '2026-11-10');

    // 1,5 stools take 6 legs worth 60 003; returned at 100,005 → 100,01 per leg they would come back as 60 006.
    $order = app(SaveProductionOrder::class)->create($this->owner, $this->company, ['article_id' => $this->stool->id, 'quantity' => '1,5']);
    $this->life->release($this->owner, $this->company, $order);
    $this->life->start($this->owner, $this->company, $order, null, true);
    $this->life->cancel($this->owner, $this->company, $order, 'Avbruten');

    expect(stockAndWip($this, '2026-11-10'))->toBe($before)
        ->and((int) ProductionOrderIssue::query()->sum('value_ore'))->toBe(0);
});

it('P3-2: a cancel puts components back on an active location when theirs is switched off', function () {
    Inventory::receive($this->company, $this->owner, $this->fx['articles']['leg'], $this->places['bin1'], '40', '350,00', '2026-11-01');
    $order = stoolOrder($this);
    $this->life->start($this->owner, $this->company, $order, null, true);
    $this->places['bin1']->forceFill(['is_active' => false])->save();

    $this->life->cancel($this->owner, $this->company, $order, 'Avbruten');

    expect($order->fresh()->status)->toBe(ProductionOrderStatus::Cancelled)
        ->and(StockMovement::query()->where('type', MovementType::ProductionIssue)->where('quantity_milli', '>', 0)->sole()->location_id)->not->toBe($this->places['bin1']->id)
        ->and(stockAndWip($this, '2026-11-10'))->toBe(1_400_000);
});

it('P3-3: the overhead of an order is fixed when it starts, so a later change does not revalue past days', function () {
    Inventory::receive($this->company, $this->owner, $this->fx['articles']['leg'], $this->places['bin1'], '40', '350,00', '2026-11-01');
    app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $this->company, $this->owner, ['overhead_bp' => 1_000, 'overhead_in_stock_value' => true]);
    $order = stoolOrder($this);
    $this->life->start($this->owner, $this->company, $order, null, true);

    // 16 legs, 560 000, plus 10 %.
    expect(WorkInProgress::at($this->company, CarbonImmutable::today())->valueOre)->toBe(616_000);

    app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $this->company, $this->owner, ['overhead_bp' => 2_000, 'overhead_in_stock_value' => false]);
    expect(WorkInProgress::at($this->company, CarbonImmutable::today())->valueOre)->toBe(616_000)
        ->and($this->life->finish($this->owner, $this->company, $order)->overhead_cost_ore)->toBe(56_000);
});

it('P3-5: refuses dates in a closed fiscal year or a locked period', function () {
    Inventory::receive($this->company, $this->owner, $this->fx['articles']['leg'], $this->places['bin1'], '40', '350,00', '2026-11-01');
    $order = stoolOrder($this);
    $this->company->forceFill(['locked_until_date' => '2026-11-05'])->save();

    expect(fn () => $this->life->start($this->owner, $this->company->fresh(), $order, '2026-11-05'))->toThrow(ValidationException::class, 'låst');

    $this->life->start($this->owner, $this->company->fresh(), $order, '2026-11-06');
    $this->company->forceFill(['locked_until_date' => null])->save();
    FiscalYear::query()->whereDate('start_date', '2026-01-01')->update(['status' => 'CLOSED']);

    expect(fn () => $this->life->finish($this->owner, $this->company->fresh(), $order))->toThrow(ValidationException::class, 'stängt');
});
