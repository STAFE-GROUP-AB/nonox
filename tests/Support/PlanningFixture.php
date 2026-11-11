<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\Engine\FreeTime;
use App\Modules\Manufacturing\Planning\Engine\PlanInput;
use App\Modules\Manufacturing\Planning\Engine\PlanOperation;
use App\Modules\Manufacturing\Planning\Engine\PlanOrder;
use App\Modules\Manufacturing\Planning\Engine\PlanPerson;
use App\Modules\Manufacturing\Planning\Engine\PlanWorkstation;
use App\Modules\Manufacturing\Planning\Engine\WeekPattern;
use App\Modules\Manufacturing\Planning\Engine\WorkingCalendar;
use Carbon\CarbonImmutable;

/**
 * The synthetic workshop of ADR-071 §13.9 (tests/Fixtures/manufacturing/
 * planning-calendar.json) as scheduler input, with a case's changes
 * applied: absence, overtime, priority, a later material date, downtime,
 * more minutes, a new quantity. Ids: people 1–5 and operations 1–5 in the
 * fixture's order, the workstation 1, a production order's id is its
 * number, an operation of an order is number × 10 + position.
 */
final class PlanningFixture
{
    /** @return array<string, mixed> */
    public static function data(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/manufacturing/planning-calendar.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function personId(string $key): int
    {
        return array_search($key, array_column(self::data()['people'], 'key'), true) + 1;
    }

    public static function operationId(string $name): int
    {
        return array_search($name, array_column(self::data()['operations'], 'name'), true) + 1;
    }

    /** @param  list<array<string, mixed>>  $changes */
    public static function input(array $changes = []): PlanInput
    {
        $data = self::data();
        $week = $data['week'];
        $now = CarbonImmutable::parse($data['now'], Clock::ZONE);
        $horizon = $now->addWeeks(26);
        $pattern = WeekPattern::uniform($week['days'], (int) Clock::minuteOfDay($week['start']), (int) Clock::minuteOfDay($week['end']), Clock::minuteOfDay($week['break_start']), $week['break_minutes']);
        $base = $pattern->intervals($now->startOfDay(), $horizon);

        $people = [];

        foreach ($data['people'] as $index => $person) {
            $intervals = $base;

            foreach ($changes as $change) {
                if (($change['person'] ?? null) !== $person['key']) {
                    continue;
                }

                if ($change['type'] === 'OVERTIME') {
                    $intervals[] = [Clock::local($change['date'], (int) Clock::minuteOfDay($change['from'])), Clock::local($change['date'], (int) Clock::minuteOfDay($change['to']))];
                }
            }

            $free = new FreeTime($intervals);

            foreach ($changes as $change) {
                if ($change['type'] === 'ABSENCE' && $change['person'] === $person['key']) {
                    $free->remove([[Clock::local($change['from'], 0), Clock::local(CarbonImmutable::parse($change['to'])->addDay()->toDateString(), 0)]]);
                }
            }

            $people[] = new PlanPerson($index + 1, $free->intervals(), array_map(self::operationId(...), $person['skills']));
        }

        $workstations = [];

        foreach ($data['workstations'] as $index => $workstation) {
            $intervals = [[Clock::minute($now->startOfDay()), Clock::minute($horizon)]];
            $free = new FreeTime($intervals);

            foreach ($changes as $change) {
                if ($change['type'] === 'DOWNTIME' && $change['workstation'] === $workstation['key']) {
                    $free->remove([[Clock::minute(CarbonImmutable::parse($change['from'], Clock::ZONE)), Clock::minute(CarbonImmutable::parse($change['to'], Clock::ZONE))]]);
                }
            }

            $workstations[] = new PlanWorkstation($index + 1, $free->intervals());
        }

        $orders = [];

        foreach ($data['orders'] as $order) {
            $quantity = $order['quantity'];
            $priority = null;
            $ready = $order['material_ready_on'];

            foreach ($changes as $change) {
                if (($change['order'] ?? null) !== $order['number']) {
                    continue;
                }

                $quantity = $change['type'] === 'QUANTITY' ? $change['quantity'] : $quantity;
                $priority = $change['type'] === 'PRIORITY' ? $change['priority'] : $priority;
                $ready = $change['type'] === 'MATERIAL' ? $change['ready_on'] : $ready;
            }

            $operations = [];

            foreach ($data['operations'] as $position => $operation) {
                $minutes = $order['minutes'][$position] * $quantity;

                if ($minutes === 0) {
                    continue;
                }

                foreach ($changes as $change) {
                    if ($change['type'] === 'REMAINING' && $change['order'] === $order['number'] && $change['operation'] === $operation['name']) {
                        $minutes += $change['add_minutes'];
                    }
                }

                $workstationIds = isset($operation['workstation']) ? [array_search($operation['workstation'], array_column($data['workstations'], 'name'), true) + 1] : [];
                $operations[] = new PlanOperation($order['number'] * 10 + $position, $position + 1, $minutes, $position + 1, $workstationIds, $operation['wait_minutes']);
            }

            $orders[] = new PlanOrder(
                $order['number'],
                $order['number'],
                $operations,
                priority: $priority,
                promisedOn: $order['promised_on'],
                earliestStart: $ready === null ? null : Clock::local($ready, 0),
                materialReadyOn: $ready,
            );
        }

        return new PlanInput(
            Clock::minute($now),
            Clock::minute($horizon),
            new WorkingCalendar($week['days'], (int) Clock::minuteOfDay($week['start'])),
            $people,
            $workstations,
            $orders,
        );
    }
}
