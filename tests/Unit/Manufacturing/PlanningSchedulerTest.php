<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Domain\ForecastStatus;
use App\Modules\Manufacturing\Domain\WaitsFor;
use App\Modules\Manufacturing\Planning\Availability;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\FreeTime;
use App\Modules\Manufacturing\Planning\Engine\MaterialPlanner;
use App\Modules\Manufacturing\Planning\Engine\PlanOperation;
use App\Modules\Manufacturing\Planning\Engine\PlanOrder;
use App\Modules\Manufacturing\Planning\Engine\PlanPin;
use App\Modules\Manufacturing\Planning\Engine\PlanSplit;
use App\Modules\Manufacturing\Planning\Engine\Scheduler;
use App\Modules\Manufacturing\Planning\Engine\WeekPattern;
use Carbon\CarbonImmutable;
use Tests\Support\PlanningFixture;

/*
 * ADR-071 §13.9: the scheduler on the synthetic workshop, golden values
 * F-P0 to F-P6, and the slot lists for F-P0 and F-P1.
 */

$baseline = fn () => (new Scheduler)->schedule(PlanningFixture::input());

dataset('fixture cases', array_keys(PlanningFixture::data()['cases']));

it('forecasts the fixture cases', function (string $case) use ($baseline) {
    $definition = PlanningFixture::data()['cases'][$case];
    $input = PlanningFixture::input($definition['changes']);
    $result = (new Scheduler)->schedule($input);
    $base = $baseline();

    foreach ($definition['expected'] as $number => [$date, $status, $change]) {
        $forecast = $result->forecast((int) $number);

        expect($forecast?->deliveryOn)->toBe($date, "{$case} TO-{$number} date")
            ->and($forecast?->status)->toBe(ForecastStatus::from($status), "{$case} TO-{$number} status");

        if ($change !== null) {
            expect($input->calendar->workingDaysBetween((string) $base->forecast((int) $number)?->deliveryOn, $date))->toBe($change, "{$case} TO-{$number} change");
        }
    }
})->with('fixture cases');

it('places the operations as the slot lists say', function (string $case) {
    $data = PlanningFixture::data();
    $result = (new Scheduler)->schedule(PlanningFixture::input($data['cases'][$case]['changes']));

    $slots = array_map(fn ($slot): array => [
        $slot->orderId,
        $data['operations'][$slot->orderOperationId % 10]['name'],
        $data['people'][$slot->employeeId - 1]['key'],
        $slot->workstationId === null ? null : $data['workstations'][$slot->workstationId - 1]['key'],
        Clock::at($slot->start)->format('Y-m-d H:i'),
        Clock::at($slot->end)->format('Y-m-d H:i'),
    ], $result->slots);

    expect($slots)->toEqualCanonicalizing($data['slots'][$case]);
})->with(['F-P0', 'F-P1']);

it('says what an order waits for', function () use ($baseline) {
    $result = $baseline();

    expect($result->forecast(1042)?->waitsFor)->toBe(WaitsFor::Nothing)
        ->and($result->forecast(1043)?->waitsFor)->toBe(WaitsFor::Material)
        ->and($result->forecast(1043)?->materialReadyOn)->toBe('2026-11-24');
});

it('gives the same plan for the same input, in any order of the orders', function () {
    $input = PlanningFixture::input();
    $shuffled = $input->with(orders: array_reverse($input->orders));

    expect((new Scheduler)->schedule($shuffled)->slots)->toEqual((new Scheduler)->schedule($input)->slots);
});

it('keeps locked work where it is and breaks a lock the person cannot keep', function () {
    $input = PlanningFixture::input();
    $tove = PlanningFixture::personId('tove');
    $lina = PlanningFixture::personId('lina');
    $orders = $input->orders;

    // TO-1041 Slipning locked on Tove, Wednesday 13:00; TO-1042 Slipning locked on Lina Tuesday 07:00, before its predecessors.
    $orders[1] = new PlanOrder(1041, 1041, array_map(fn (PlanOperation $op): PlanOperation => $op->id === 10413
        ? new PlanOperation($op->id, $op->operationId, $op->minutes, $op->skillId, $op->workstationIds, $op->waitMinutes, new PlanPin(7, $tove, null, Clock::local('2026-11-18', 13 * 60)))
        : $op, $orders[1]->operations), promisedOn: '2026-11-25');

    $result = (new Scheduler)->schedule($input->with(orders: $orders));
    $locked = collect($result->slots)->firstWhere('orderOperationId', 10413);

    expect($locked->locked)->toBeTrue()
        ->and($locked->employeeId)->toBe($tove)
        ->and(Clock::at($locked->start)->format('Y-m-d H:i'))->toBe('2026-11-18 13:00')
        ->and($result->brokenLocks)->toBe([]);

    // The same lock on a day Tove is absent is broken and the operation is planned anyway.
    $absent = PlanningFixture::input([['type' => 'ABSENCE', 'person' => 'tove', 'from' => '2026-11-17', 'to' => '2026-11-23']]);
    $orders = $absent->orders;
    $orders[1] = new PlanOrder(1041, 1041, array_map(fn (PlanOperation $op): PlanOperation => $op->id === 10413
        ? new PlanOperation($op->id, $op->operationId, $op->minutes, $op->skillId, $op->workstationIds, $op->waitMinutes, new PlanPin(7, $tove, null, Clock::local('2026-11-18', 13 * 60)))
        : $op, $orders[1]->operations), promisedOn: '2026-11-25');

    $broken = (new Scheduler)->schedule($absent->with(orders: $orders));

    expect($broken->brokenLocks)->toBe([7])
        ->and($broken->forecast(1041)?->has('lock_broken'))->toBeTrue()
        ->and(collect($broken->slots)->firstWhere('orderOperationId', 10413)->employeeId)->toBe($lina);
});

it('moves an operation to the person it is assigned to and splits one between two people', function () {
    $input = PlanningFixture::input();
    $maja = PlanningFixture::personId('maja');
    $omar = PlanningFixture::personId('omar');
    $orders = $input->orders;

    // TO-1041 Montering (900 min) assigned to Maja, and split 300 minutes to Omar.
    $orders[1] = new PlanOrder(1041, 1041, array_map(fn (PlanOperation $op): PlanOperation => $op->id === 10412
        ? new PlanOperation($op->id, $op->operationId, $op->minutes, $op->skillId, $op->workstationIds, $op->waitMinutes, new PlanPin(1, $maja), new PlanSplit(2, $omar, 300))
        : $op, $orders[1]->operations), promisedOn: '2026-11-25');

    $result = (new Scheduler)->schedule($input->with(orders: $orders));
    $parts = collect($result->slots)->where('orderOperationId', 10412)->values();

    expect($parts)->toHaveCount(2)
        ->and($parts->firstWhere('part', 1)->employeeId)->toBe($omar)
        ->and($parts->firstWhere('part', 1)->minutes)->toBe(300)
        ->and($parts->firstWhere('part', 0)->employeeId)->toBe($maja)
        ->and($parts->firstWhere('part', 0)->minutes)->toBe(600);
});

it('forecasts nothing past the horizon', function () {
    $input = PlanningFixture::input();
    $orders = $input->orders;
    $orders[0] = new PlanOrder(1042, 1042, [new PlanOperation(10420, 1, 60 * 24 * 365, 1)], promisedOn: '2026-11-24');

    $forecast = (new Scheduler)->schedule($input->with(orders: $orders))->forecast(1042);

    expect($forecast?->deliveryOn)->toBeNull()
        ->and($forecast?->status)->toBe(ForecastStatus::Red)
        ->and($forecast?->has('beyond_horizon'))->toBeTrue();
});

it('fills free time across breaks and gaps, and books it', function () {
    $free = new FreeTime([[0, 100], [200, 300]]);

    $fill = $free->fill(50, 100);
    expect($fill?->segments)->toBe([[50, 100], [200, 250]]);

    $free->book($fill->segments);
    expect($free->intervals())->toBe([[0, 50], [250, 300]])
        ->and($free->fill(0, 200))->toBeNull()
        ->and($free->fill(0, 60, new FreeTime([[20, 280]]))?->segments)->toBe([[20, 50], [250, 280]]);
});

it('allocates stock and incoming purchase orders in planning order', function () {
    $ready = MaterialPlanner::allocate(
        [1, 2, 3],
        [1 => [10 => 5_000], 2 => [10 => 5_000], 3 => [10 => 5_000, 11 => 1_000]],
        [10 => 7_000],
        [10 => [['2026-11-30', 2_000], ['2026-11-24', 2_000], [null, 9_000]]],
        // 1: from stock; 2: 2 from stock, 2 on the 24th, 1 on the 30th; 3: the last 1 of the 30th, the rest assumed.
        [],
        '2026-11-16',
        10,
    );

    expect($ready[1])->toBe([null, false])
        ->and($ready[2])->toBe(['2026-11-30', false])
        ->and($ready[3])->toBe(['2026-11-30', true]);
});

it('takes a part-day absence out of every day it covers, and a full day\'s hours as the whole day', function () {
    $from = CarbonImmutable::parse('2026-11-16', Clock::ZONE);
    $week = WeekPattern::uniform([1, 2, 3, 4, 5], 420, 960, 720, 60);
    $free = new FreeTime($week->intervals($from, $from->addDays(6)));

    $days = Availability::removeAbsence($free, '2026-11-16', '2026-11-17', 10_000, 4.0, $from, $from->addDays(6));

    expect($days)->toBe(['2026-11-16', '2026-11-17'])
        ->and($free->minutesBetween(Clock::local('2026-11-16', 0), Clock::local('2026-11-17', 0)))->toBe(240)
        ->and($free->minutesBetween(Clock::local('2026-11-17', 0), Clock::local('2026-11-18', 0)))->toBe(240);

    Availability::removeAbsence($free, '2026-11-18', '2026-11-18', 10_000, 8.0, $from, $from->addDays(6));
    expect($free->minutesBetween(Clock::local('2026-11-18', 0), Clock::local('2026-11-19', 0)))->toBe(0);
});

it('flags locked work that starts before an earlier operation ends, and books nothing when a split cannot be done', function () {
    $input = PlanningFixture::input();
    $tove = PlanningFixture::personId('tove');
    $orders = $input->orders;

    // TO-1042 Slipning locked Monday 07:00, before Kapning, Hyvling and Montering can be done.
    $orders[0] = new PlanOrder(1042, 1042, array_map(fn (PlanOperation $op): PlanOperation => $op->id === 10423
        ? new PlanOperation($op->id, $op->operationId, $op->minutes, $op->skillId, $op->workstationIds, $op->waitMinutes, new PlanPin(9, $tove, null, Clock::local('2026-11-16', 7 * 60)))
        : $op, $orders[0]->operations), promisedOn: '2026-11-24');

    expect((new Scheduler)->schedule($input->with(orders: $orders))->forecast(1042)?->has('lock_out_of_order'))->toBeTrue();

    // Lackering split to Tove: the rest must go to someone else with the skill, and nobody else lacquers.
    $orders = $input->orders;
    $orders[0] = new PlanOrder(1042, 1042, array_map(fn (PlanOperation $op): PlanOperation => $op->id === 10424
        ? new PlanOperation($op->id, $op->operationId, $op->minutes, $op->skillId, $op->workstationIds, $op->waitMinutes, null, new PlanSplit(3, $tove, 60))
        : $op, $orders[0]->operations), promisedOn: '2026-11-24');

    $result = (new Scheduler)->schedule($input->with(orders: $orders));

    expect(collect($result->slots)->where('orderOperationId', 10424)->all())->toBe([])
        ->and($result->forecast(1042)?->has('no_resource'))->toBeTrue();
});
