<?php

declare(strict_types=1);

use App\Domain\Company\VatPeriod;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Modules\Inventory\Actions\BookStockValuation;
use App\Modules\Inventory\Support\StockValuationReport;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Domain\LabourSource;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\Models\ProductionLabourEntry;
use App\Modules\Manufacturing\Models\ProductionOrder;
use App\Modules\Manufacturing\Production\WorkInProgress;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Manufacturing;

/*
 * Production orders at year end (ADR-071 §8), fixtures F-V1–F-V3 of
 * tests/Fixtures/manufacturing/production-orders.json: the components a
 * started order has taken leave the shelves and come back as work in
 * progress on its own account pair (1440/4940 by default), labour reported
 * on it is added, and a finished order's goods are stock at their
 * manufacturing cost. No voucher is booked per order.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 10:00:00'));
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Manufacturing::company();
    $this->company->forceFill(['vat_period' => VatPeriod::None])->save();
    $this->actingAs($this->owner);
    $this->fx = Manufacturing::fixtures($this->company, $this->owner);
    $this->fixtures = collect(Manufacturing::productionFixtures()['valuation'])->keyBy('id');
    Manufacturing::stock($this->company, $this->owner, $this->fx, $this->places['bin1']);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->life = app(ProductionOrderLifecycle::class);
    $this->order = app(SaveProductionOrder::class)->create($this->owner, $this->company, ['article_id' => $this->fx['articles']['table']->id, 'quantity' => '2', 'location_id' => $this->places['bin2']->id]);
    $this->life->release($this->owner, $this->company, $this->order);
    $this->life->start($this->owner, $this->company, $this->order, '2026-12-10', true);
});

/** @return array<int, int> account => signed öre */
function productionVoucherRows(string $id): array
{
    $voucher = Voucher::query()->with('rows.account')->findOrFail($id);

    return $voucher->rows->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->sort()->all();
}

it('F-V1: counts the issued components of an unfinished order as work in progress at year end', function () {
    $case = $this->fixtures['F-V1'];
    $this->travelTo(CarbonImmutable::parse('2027-01-10 10:00:00'));

    expect(StockValuationReport::at($this->company, CarbonImmutable::parse('2026-11-30'))->costValueOre)->toBe($case['stock_ore_before'])
        ->and(StockValuationReport::at($this->company, $this->year->end_date)->costValueOre)->toBe($case['stock_ore_after'])
        ->and(WorkInProgress::at($this->company, $this->year->end_date)->valueOre)->toBe($case['wip_ore'])
        ->and(WorkInProgress::at($this->company, CarbonImmutable::parse('2026-12-09'))->valueOre)->toBe(0);

    $plan = BookStockValuation::plan($this->company, $this->year);
    $wip = collect($plan['groups'])->firstWhere('stock_account', 1440);

    expect($plan['value_ore'])->toBe($case['stock_ore_after'] + $case['wip_ore'])
        ->and($wip)->toMatchArray(['classes' => ['WORK_IN_PROGRESS'], 'change_account' => 4940, 'value_ore' => $case['wip_ore']])
        ->and($wip['additions'][0]['lines'][0])->toMatchArray(['reference' => 'TO-1', 'name' => 'Ekbord 180', 'value_ore' => $case['wip_ore']]);

    $valuation = app(BookStockValuation::class)($this->owner, $this->company, $this->year);

    CurrentCompany::set($this->company);
    expect(productionVoucherRows($valuation->voucher_id))->toBe([4960 => -$case['stock_ore_after'], 4940 => -$case['wip_ore'], 1440 => $case['wip_ore'], 1460 => $case['stock_ore_after']])
        ->and($valuation->value_ore)->toBe($case['stock_ore_after'] + $case['wip_ore'])
        ->and(collect($valuation->classes)->pluck('value_ore', 'stock_account')->all())->toBe([1460 => $case['stock_ore_after'], 1440 => $case['wip_ore']])
        ->and(Voucher::query()->count())->toBe(1);
});

it('F-V2: adds the labour reported on the order up to the day', function () {
    $operation = $this->order->operations->firstWhere('operation_id', $this->fx['operations']['assembly']->id);
    ProductionLabourEntry::create(['production_order_id' => $this->order->id, 'production_order_operation_id' => $operation->id, 'source' => LabourSource::Terminal, 'minutes' => 120, 'hourly_cost_ore' => 48_000, 'cost_ore' => 96_000, 'worked_on' => '2026-12-20']);

    expect(WorkInProgress::at($this->company, $this->year->end_date)->valueOre)->toBe($this->fixtures['F-V2']['wip_ore'])
        ->and(WorkInProgress::at($this->company, CarbonImmutable::parse('2026-12-19'))->valueOre)->toBe($this->fixtures['F-V1']['wip_ore']);
});

it('F-V3: a finished order leaves no work in progress, and its goods are stock at their cost', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00:00'));
    $this->life->finish($this->owner, $this->company, $this->order);
    $this->travelTo(CarbonImmutable::parse('2027-01-10 10:00:00'));
    $case = $this->fixtures['F-V3'];

    $report = StockValuationReport::at($this->company, $this->year->end_date);
    $table = collect($report->lines)->firstWhere('article_id', $this->fx['articles']['table']->id);

    expect(WorkInProgress::at($this->company, $this->year->end_date)->valueOre)->toBe($case['wip_ore'])
        ->and($table['cost_value_ore'])->toBe($case['finished_goods_ore'])
        ->and($table['unit_cost_ore'])->toBe(513_012)
        ->and(collect(BookStockValuation::plan($this->company, $this->year)['groups'])->pluck('stock_account')->all())->toBe([1460])
        ->and(WorkInProgress::at($this->company, CarbonImmutable::parse('2026-12-14'))->valueOre)->toBe($this->fixtures['F-V1']['wip_ore']);
});

it('includes the overhead in the work in progress only when the company has chosen it', function () {
    app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $this->company, $this->owner, ['overhead_bp' => 1_000]);
    expect(WorkInProgress::at($this->company, $this->year->end_date)->valueOre)->toBe(785_024);

    app(ModuleManager::class)->updateSettings(ManufacturingModule::KEY, $this->company, $this->owner, ['overhead_bp' => 1_000, 'overhead_in_stock_value' => true]);
    // 785 024 × 10 % = 78 502,4 → 78 502.
    expect(WorkInProgress::at($this->company, $this->year->end_date)->valueOre)->toBe(863_526);
});

it('books the work in progress down when the order is cancelled before year end', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
    $this->life->cancel($this->owner, $this->company, $this->order, 'Avbeställd');

    expect(WorkInProgress::at($this->company, $this->year->end_date)->valueOre)->toBe(0)
        ->and(WorkInProgress::at($this->company, CarbonImmutable::parse('2026-12-19'))->valueOre)->toBe(785_024)
        ->and(StockValuationReport::at($this->company, $this->year->end_date)->costValueOre)->toBe(2_415_000)
        ->and(ProductionOrder::query()->sole()->cancelled_on->toDateString())->toBe('2026-12-20');
});
