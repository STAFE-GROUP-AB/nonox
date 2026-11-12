<?php

declare(strict_types=1);

use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\PlanInput;
use App\Modules\Manufacturing\Planning\Engine\PlanOperation;
use App\Modules\Manufacturing\Planning\Engine\PlanOrder;
use App\Modules\Manufacturing\Planning\Engine\PlanPerson;
use App\Modules\Manufacturing\Planning\Engine\PlanWorkstation;
use App\Modules\Manufacturing\Planning\Engine\Scheduler;
use App\Modules\Manufacturing\Planning\Engine\WeekPattern;
use App\Modules\Manufacturing\Planning\Engine\WorkingCalendar;
use Carbon\CarbonImmutable;

/*
 * ADR-071 §13.4: the scheduler handles 300 production orders × 8
 * operations × 20 resources in under two seconds. Synthetic and seeded,
 * so the same plan every run.
 */
it('plans 300 orders of 8 operations on 20 resources in under two seconds', function () {
    mt_srand(71);
    $now = CarbonImmutable::parse('2026-11-16 07:00', Clock::ZONE);
    $horizon = $now->addWeeks(26);
    $intervals = WeekPattern::uniform([1, 2, 3, 4, 5], 420, 960, 720, 60)->intervals($now->startOfDay(), $horizon);

    $people = [];

    for ($id = 1; $id <= 16; $id++) {
        $people[] = new PlanPerson($id, $intervals, [($id % 8) + 1, (($id + 3) % 8) + 1, (($id + 5) % 8) + 1]);
    }

    $workstations = [];

    for ($id = 1; $id <= 4; $id++) {
        $workstations[] = new PlanWorkstation($id, [[Clock::minute($now), Clock::minute($horizon)]]);
    }

    $orders = [];

    for ($number = 1; $number <= 300; $number++) {
        $operations = [];

        for ($position = 0; $position < 8; $position++) {
            $operations[] = new PlanOperation($number * 10 + $position, $position + 1, mt_rand(30, 240), $position + 1, $position === 7 ? [mt_rand(1, 4)] : [], $position === 7 ? 600 : 0);
        }

        $orders[] = new PlanOrder($number, $number, $operations, promisedOn: $now->addDays(mt_rand(5, 120))->toDateString());
    }

    $input = new PlanInput(Clock::minute($now), Clock::minute($horizon), new WorkingCalendar([1, 2, 3, 4, 5], 420), $people, $workstations, $orders);

    $started = hrtime(true);
    $result = (new Scheduler)->schedule($input);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect($result->forecasts)->toHaveCount(300)
        ->and(count($result->slots))->toBe(2400)
        ->and($seconds)->toBeLessThan(2.0);
});
