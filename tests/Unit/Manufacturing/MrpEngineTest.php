<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Mrp\DemandKind;
use App\Modules\Manufacturing\Mrp\MrpArticle;
use App\Modules\Manufacturing\Mrp\MrpDemand;
use App\Modules\Manufacturing\Mrp\MrpEngine;
use App\Modules\Manufacturing\Mrp\MrpInput;
use App\Modules\Manufacturing\Mrp\MrpReceipt;
use App\Modules\Manufacturing\Mrp\MrpStructureLoop;
use App\Modules\Manufacturing\Mrp\PlannedOrderException;
use App\Modules\Manufacturing\Mrp\PlannedOrderKind;
use App\Modules\Manufacturing\Mrp\PlannedOrderProposal;
use App\Modules\Manufacturing\Mrp\SupplyMessageKind;
use App\Modules\Manufacturing\Mrp\SupplySource;
use Carbon\CarbonImmutable;

/*
 * The MRP engine on hand-made inputs (ADR-081 gap 5, E16). Every expected
 * figure below can be recomputed by hand: weekly buckets from Monday
 * 2026-11-16 (today is Wednesday 2026-11-18), the larger of forecast and
 * customer orders per week, projected stock against the safety stock, lots
 * and lead times. Quantities in milli-units.
 */

/** @param  array<string, mixed>  $extra */
function e16Article(int $id, array $extra = []): MrpArticle
{
    return new MrpArticle(
        id: $id,
        kind: $extra['kind'] ?? PlannedOrderKind::Purchase,
        onHandMilli: $extra['on_hand'] ?? 0,
        safetyStockMilli: $extra['safety'] ?? 0,
        leadTimeDays: $extra['lead'] ?? 0,
        lotMinimumMilli: $extra['lot_min'] ?? null,
        lotMultipleMilli: $extra['lot_multiple'] ?? null,
        supplierId: $extra['supplier'] ?? null,
        bom: $extra['bom'] ?? null,
    );
}

/**
 * @param  list<MrpArticle>  $articles
 * @param  list<MrpDemand>  $demands
 * @param  array<int, array<string, int>>  $forecast
 * @param  list<MrpReceipt>  $receipts
 */
function e16Input(array $articles, array $demands = [], array $forecast = [], array $receipts = [], int $weeks = 13): MrpInput
{
    $keyed = [];

    foreach ($articles as $article) {
        $keyed[$article->id] = $article;
    }

    return new MrpInput(CarbonImmutable::parse('2026-11-18'), CarbonImmutable::parse('2026-11-16'), $weeks, $keyed, $demands, $forecast, $receipts);
}

function e16Order(int $article, string $date, int $milli, int $number = 1001): MrpDemand
{
    return new MrpDemand($article, DemandKind::CustomerOrder, $date, $milli, "order-{$number}", $number);
}

/** @return list<PlannedOrderProposal> */
function e16For(array $orders, int $article): array
{
    return array_values(array_filter($orders, fn (PlannedOrderProposal $order): bool => $order->articleId === $article));
}

it('takes the larger of forecast and customer orders in a week, never the sum', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1, ['lead' => 7])],
        [e16Order(1, '2026-11-25', 6000), e16Order(1, '2026-12-02', 12_000, 1002)],
        [1 => ['2026-11-23' => 10_000, '2026-11-30' => 10_000]],
    ));

    $orders = e16For($plan->plannedOrders, 1);

    // Week 2 (23 Nov): forecast 10 > orders 6 → 10. Week 3 (30 Nov): orders 12 > forecast 10 → 12.
    expect($orders)->toHaveCount(2)
        ->and($orders[0]->quantityMilli)->toBe(10_000)
        ->and($orders[0]->dueOn)->toBe('2026-11-23')
        ->and($orders[0]->releaseOn)->toBe('2026-11-18')
        ->and($orders[1]->quantityMilli)->toBe(12_000)
        ->and($orders[1]->dueOn)->toBe('2026-11-30');

    // The forecast is pegged only for what it adds above the orders.
    expect($orders[0]->pegging)->toBe([
        ['kind' => 'CUSTOMER_ORDER', 'quantity_milli' => 6000, 'on' => '2026-11-25', 'id' => 'order-1001', 'number' => 1001, 'article_id' => null],
        ['kind' => 'FORECAST', 'quantity_milli' => 4000, 'on' => '2026-11-23', 'id' => null, 'number' => null, 'article_id' => null],
    ])->and(array_column($orders[1]->pegging, 'kind'))->toBe(['CUSTOMER_ORDER']);
});

it('nets against stock on hand and keeps the safety stock', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1, ['on_hand' => 15_000, 'safety' => 5000])],
        [e16Order(1, '2026-11-24', 8000), e16Order(1, '2026-12-01', 8000, 1002)],
    ));

    // Week 2: 15 − 8 = 7 ≥ 5. Week 3: 7 − 8 = −1, so 6 is planned to reach the safety stock of 5.
    $orders = e16For($plan->plannedOrders, 1);

    expect($orders)->toHaveCount(1)
        ->and($orders[0]->quantityMilli)->toBe(6000)
        ->and($orders[0]->dueOn)->toBe('2026-11-30')
        ->and($orders[0]->pegging)->toBe([
            ['kind' => 'CUSTOMER_ORDER', 'quantity_milli' => 1000, 'on' => '2026-12-01', 'id' => 'order-1002', 'number' => 1002, 'article_id' => null],
            ['kind' => 'SAFETY_STOCK', 'quantity_milli' => 5000, 'on' => '2026-11-30', 'id' => null, 'number' => null, 'article_id' => null],
        ]);
});

it('raises a planned order to the minimum lot and rounds it up to the multiple', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1, ['lot_min' => 20_000, 'lot_multiple' => 12_000])],
        [e16Order(1, '2026-11-30', 3000), e16Order(1, '2026-12-28', 30_000, 1002)],
    ));

    $orders = e16For($plan->plannedOrders, 1);

    // 3 → minimum 20 → multiple of 12: 24. Leaves 21 in stock; the next week needs 30 − 21 = 9 → 20 → 24.
    expect(array_map(fn (PlannedOrderProposal $order): int => $order->quantityMilli, $orders))->toBe([24_000, 24_000])
        ->and($orders[1]->pegging[0]['quantity_milli'])->toBe(9000);
});

it('offsets by the lead time and marks late and release-now orders', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1, ['lead' => 14]), e16Article(2, ['lead' => 10]), e16Article(3, ['lead' => 3])],
        [e16Order(1, '2026-11-26', 1000), e16Order(2, '2026-12-03', 1000, 1002), e16Order(3, '2027-01-12', 1000, 1003)],
    ));

    [$late] = e16For($plan->plannedOrders, 1);
    [$now] = e16For($plan->plannedOrders, 2);
    [$later] = e16For($plan->plannedOrders, 3);

    // Due 23 Nov − 14 days = 9 Nov, already past: released today, late.
    expect($late->exception)->toBe(PlannedOrderException::Late)
        ->and($late->releaseOn)->toBe('2026-11-18')
        ->and($late->dueOn)->toBe('2026-11-23')
        // Due 30 Nov − 10 = 20 Nov, within seven days of today.
        ->and($now->exception)->toBe(PlannedOrderException::ReleaseNow)
        ->and($now->releaseOn)->toBe('2026-11-20')
        // Due 11 Jan − 3 = 8 Jan: no advice.
        ->and($later->exception)->toBeNull()
        ->and($later->releaseOn)->toBe('2027-01-08')
        ->and($plan->exceptionCount())->toBe(2);
});

it('puts overdue demand in the first week, due today, and leaves out what lies beyond the horizon', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1)],
        [e16Order(1, '2026-11-02', 2000), e16Order(1, '2027-06-01', 9000, 1002)],
        weeks: 13,
    ));

    $orders = e16For($plan->plannedOrders, 1);

    expect($orders)->toHaveCount(1)
        ->and($orders[0]->quantityMilli)->toBe(2000)
        ->and($orders[0]->dueOn)->toBe('2026-11-18')
        ->and($orders[0]->exception)->toBe(PlannedOrderException::ReleaseNow);
});

it('moves the needed part of a later receipt forward before it plans anything new, and calls the rest excess', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1)],
        [e16Order(1, '2026-11-24', 5000)],
        receipts: [new MrpReceipt(1, SupplySource::PurchaseOrder, 77, 4001, '2026-12-14', 8000)],
    ));

    // 5 of the 8 are needed on 23 Nov (split the order); the other 3 are needed by nothing in the horizon.
    expect($plan->plannedOrders)->toBe([])
        ->and($plan->messages)->toBe([
            ['kind' => 'MOVE_IN', 'article_id' => 1, 'source' => 'PURCHASE_ORDER', 'id' => 77, 'number' => 4001, 'on' => '2026-12-14', 'to' => '2026-11-23', 'quantity_milli' => 5000, 'order_milli' => 8000],
            ['kind' => 'EXCESS', 'article_id' => 1, 'source' => 'PURCHASE_ORDER', 'id' => 77, 'number' => 4001, 'on' => '2026-12-14', 'to' => null, 'quantity_milli' => 3000, 'order_milli' => 8000],
        ]);
});

it('moves a later receipt forward for a safety stock shortfall instead of proposing a new order and calling the receipt excess', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1, ['safety' => 10_000])],
        receipts: [new MrpReceipt(1, SupplySource::PurchaseOrder, 5, 4002, '2026-12-07', 10_000)],
    ));

    expect($plan->plannedOrders)->toBe([])
        ->and($plan->messages)->toBe([
            ['kind' => 'MOVE_IN', 'article_id' => 1, 'source' => 'PURCHASE_ORDER', 'id' => 5, 'number' => 4002, 'on' => '2026-12-07', 'to' => '2026-11-18', 'quantity_milli' => 10_000, 'order_milli' => 10_000],
        ]);
});

it('never calls a receipt excess while a planned order covers a need the receipt is also counted for', function () {
    // Demand of 20 in week 4; a receipt of 10 in week 2. The receipt is needed, the planned order covers the other 10.
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1, ['lot_min' => 30_000])],
        [e16Order(1, '2026-12-14', 20_000)],
        receipts: [new MrpReceipt(1, SupplySource::PurchaseOrder, 6, 4003, '2026-11-30', 10_000)],
    ));

    expect(array_map(fn (PlannedOrderProposal $order): int => $order->quantityMilli, $plan->plannedOrders))->toBe([30_000])
        ->and($plan->messages)->toBe([]);
});

it('says how much of a receipt is not needed within the horizon', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(1, ['on_hand' => 2000, 'safety' => 1000])],
        [e16Order(1, '2026-12-08', 4000)],
        receipts: [new MrpReceipt(1, SupplySource::ProductionOrder, 12, 1042, '2026-11-30', 10_000)],
    ));

    // 2 + 10 − 4 = 8 at the end; keeping the safety stock of 1, 7 of the 10 are not needed.
    expect($plan->plannedOrders)->toBe([])
        ->and($plan->messages)->toHaveCount(1)
        ->and($plan->messages[0]['kind'])->toBe(SupplyMessageKind::Excess->value)
        ->and($plan->messages[0]['quantity_milli'])->toBe(7000)
        ->and($plan->messages[0]['to'])->toBeNull();
});

it('plans level by level: a planned production order becomes demand for its components on its release date', function () {
    $bom = ['yield_milli' => 1000, 'rows' => [
        ['component_article_id' => 2, 'quantity_milli' => 4000, 'loss_bp' => 0],
        ['component_article_id' => 3, 'quantity_milli' => 900, 'loss_bp' => 1000],
        ['component_article_id' => 99, 'quantity_milli' => 1000, 'loss_bp' => 0],
    ]];

    $plan = (new MrpEngine)->plan(e16Input(
        // Listed component first, to show the order does not depend on the input.
        [e16Article(2, ['on_hand' => 3000, 'lead' => 5, 'supplier' => 7]), e16Article(3), e16Article(1, ['kind' => PlannedOrderKind::Production, 'lead' => 7, 'bom' => $bom])],
        [e16Order(1, '2026-12-09', 2000)],
    ));

    [$table] = e16For($plan->plannedOrders, 1);
    [$legs] = e16For($plan->plannedOrders, 2);
    [$lacquer] = e16For($plan->plannedOrders, 3);

    // The table: due 7 Dec, released 30 Nov. Legs: 2 × 4 = 8 on 30 Nov, 3 on hand → 5, due 30 Nov, released 25 Nov.
    expect($table->kind)->toBe(PlannedOrderKind::Production)
        ->and($table->supplierId)->toBeNull()
        ->and($table->releaseOn)->toBe('2026-11-30')
        ->and($legs->quantityMilli)->toBe(5000)
        ->and($legs->dueOn)->toBe('2026-11-30')
        ->and($legs->releaseOn)->toBe('2026-11-25')
        ->and($legs->supplierId)->toBe(7)
        ->and($legs->pegging)->toBe([['kind' => 'PLANNED_PARENT', 'quantity_milli' => 5000, 'on' => '2026-11-30', 'id' => null, 'number' => null, 'article_id' => 1]])
        // Lacquer 0,9 with 10 % loss: gross 1,0 per table, 2,0 for two.
        ->and($lacquer->quantityMilli)->toBe(2000)
        // An unplanned component (99) gets nothing.
        ->and(e16For($plan->plannedOrders, 99))->toBe([]);
});

it('counts components of open production orders and receipts of production orders', function () {
    $plan = (new MrpEngine)->plan(e16Input(
        [e16Article(2), e16Article(1)],
        [new MrpDemand(2, DemandKind::ProductionOrder, '2026-11-25', 4000, 31, 1043), e16Order(1, '2026-12-01', 3000)],
        receipts: [new MrpReceipt(1, SupplySource::ProductionOrder, 31, 1043, '2026-11-27', 3000)],
    ));

    [$component] = e16For($plan->plannedOrders, 2);

    expect(e16For($plan->plannedOrders, 1))->toBe([])
        ->and($component->quantityMilli)->toBe(4000)
        ->and($component->pegging[0])->toMatchArray(['kind' => 'PRODUCTION_ORDER', 'id' => 31, 'number' => 1043]);
});

it('refuses bills of materials that go round in a circle', function () {
    $loop = fn (int $component): array => ['yield_milli' => 1000, 'rows' => [['component_article_id' => $component, 'quantity_milli' => 1000, 'loss_bp' => 0]]];

    expect(fn () => (new MrpEngine)->plan(e16Input([
        e16Article(1, ['kind' => PlannedOrderKind::Production, 'bom' => $loop(2)]),
        e16Article(2, ['kind' => PlannedOrderKind::Production, 'bom' => $loop(1)]),
    ])))->toThrow(MrpStructureLoop::class);
});

it('gives the same plan and the same input hash for the same input', function () {
    $input = fn (): MrpInput => e16Input(
        [e16Article(1, ['safety' => 1000, 'lead' => 3]), e16Article(2)],
        [e16Order(1, '2026-11-30', 4000), e16Order(2, '2026-12-07', 1000, 1002)],
        [1 => ['2026-12-07' => 2000]],
    );

    expect((new MrpEngine)->plan($input()))->toEqual((new MrpEngine)->plan($input()))
        ->and($input()->hash())->toBe($input()->hash())
        ->and($input()->hash())->not->toBe(e16Input([e16Article(1)])->hash());
});

it('plans nothing for an article whose stock covers everything', function () {
    $plan = (new MrpEngine)->plan(e16Input([e16Article(1, ['on_hand' => 50_000, 'safety' => 10_000])], [e16Order(1, '2026-12-01', 20_000)], [1 => ['2026-12-14' => 15_000]]));

    expect($plan->plannedOrders)->toBe([])->and($plan->messages)->toBe([]);
});
