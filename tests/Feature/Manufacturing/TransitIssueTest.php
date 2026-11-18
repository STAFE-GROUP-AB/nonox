<?php

declare(strict_types=1);

use App\Modules\Inventory\Actions\SaveLocation;
use App\Modules\Inventory\Actions\StockTransfers;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Manufacturing\Actions\Production\ProductionOrderLifecycle;
use App\Modules\Manufacturing\Actions\Production\SaveProductionOrder;
use App\Modules\Manufacturing\Models\ProductionOrderIssue;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\Manufacturing;

/*
 * Goods in transit (ADR-081 E13) are not on a shelf: a production order never
 * draws its components from the transit place.
 */

it('does not issue components that are only in transit', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00'));
    ['owner' => $owner, 'company' => $company, 'places' => $places] = Manufacturing::company();
    $this->actingAs($owner);
    $fx = Manufacturing::fixtures($company, $owner);
    Manufacturing::stock($company, $owner, $fx, $places['bin1']);

    $shop = app(SaveLocation::class)($owner, $company, ['type' => 'WAREHOUSE', 'code' => 'BU', 'name' => 'Butiken']);
    $lines = StockLevel::query()->where('location_id', $places['bin1']->id)->where('quantity_milli', '>', 0)->get()
        ->map(fn (StockLevel $level): array => ['article_id' => $level->article_id, 'quantity' => (string) ($level->quantity_milli / 1000)])->all();
    $draft = app(StockTransfers::class)->save($owner, $company, ['from_location_id' => $places['bin1']->id, 'to_location_id' => $shop->id, 'lines' => $lines]);
    app(StockTransfers::class)->send($owner, $company, $draft, CarbonImmutable::parse('2026-11-10'));

    $life = app(ProductionOrderLifecycle::class);
    $order = app(SaveProductionOrder::class)->create($owner, $company, ['article_id' => $fx['articles']['table']->id, 'quantity' => '2', 'location_id' => $places['bin2']->id]);
    $life->release($owner, $company, $order);

    $transit = StockLocation::query()->where('type', 'TRANSIT')->pluck('id');
    $before = StockLevel::query()->whereIn('location_id', $transit)->sum('quantity_milli');

    // Refused, or drawn from physical places (negative stock): never from the transit place.
    try {
        $life->start($owner, $company, $order);
    } catch (ValidationException) {
    }

    expect($before)->toBeGreaterThan(0)
        ->and(StockLevel::query()->whereIn('location_id', $transit)->sum('quantity_milli'))->toBe($before)
        ->and(ProductionOrderIssue::query()->whereIn('location_id', $transit)->count())->toBe(0);
});
