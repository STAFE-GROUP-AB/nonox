<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

use App\Modules\Manufacturing\Domain\ForecastStatus;
use App\Modules\Manufacturing\Domain\WaitsFor;

/**
 * Deterministic forward finite-capacity list scheduling (ADR-071 §13.3),
 * pure and in memory: the same input always gives the same plan.
 *
 * Locked work is placed first, as it is. Then each production order in
 * the total order of step 3 (PlanOrder::compare), each operation in
 * sequence: it may start at the latest of now, the previous operation's end
 * plus its waiting time, and the material ready date for the first one. It
 * fills the free working time of a qualified person (and of a workstation
 * when it needs one) from there, across breaks and days and in the gaps
 * earlier operations left. The candidate that finishes first wins; a tie
 * goes to the fewest minutes booked so far, then the lowest id. An
 * operation is never split unless a planner split it.
 *
 * The plan is greedy, not optimised: it does not minimise total lateness,
 * has no sequence-dependent setup times, and forecasts nothing past the
 * horizon.
 */
final class Scheduler
{
    /** @var array<int, FreeTime> */
    private array $people = [];

    /** @var array<int, PlanPerson> */
    private array $persons = [];

    /** @var array<int, FreeTime> */
    private array $workstations = [];

    /** @var array<int, int> minutes booked per person */
    private array $bookedPerson = [];

    /** @var array<int, int> minutes booked per workstation */
    private array $bookedWorkstation = [];

    /** @var list<PlanSlot> */
    private array $slots = [];

    public function schedule(PlanInput $input): PlanResult
    {
        $this->reset($input);

        $orders = $input->orders;
        usort($orders, PlanOrder::compare(...));

        /** @var array<int, array{0: int, 1: int}> $done first and last minute of locked operations */
        $done = [];
        $brokenLocks = [];
        $brokenOrders = [];

        foreach ($orders as $order) {
            foreach ($order->operations as $operation) {
                if ($operation->pin === null || ! $operation->pin->isLock() || $operation->minutes <= 0) {
                    continue;
                }

                $placed = $this->placeLocked($order, $operation);

                if ($placed === null) {
                    $brokenLocks[] = $operation->pin->lockId;
                    $brokenOrders[$order->id] = true;

                    continue;
                }

                $done[$operation->id] = $placed;
            }
        }

        $forecasts = [];

        foreach ($orders as $order) {
            $forecasts[$order->id] = $this->planOrder($input, $order, $done, isset($brokenOrders[$order->id]));
        }

        return new PlanResult($this->slots, $forecasts, $brokenLocks);
    }

    private function reset(PlanInput $input): void
    {
        $this->people = [];
        $this->persons = [];
        $this->workstations = [];
        $this->bookedPerson = [];
        $this->bookedWorkstation = [];
        $this->slots = [];

        $outside = [[PHP_INT_MIN, $input->now], [$input->horizonEnd, PHP_INT_MAX]];

        foreach ($input->people as $person) {
            $free = new FreeTime($person->intervals);
            $free->remove($outside);
            $this->people[$person->id] = $free;
            $this->persons[$person->id] = $person;
            $this->bookedPerson[$person->id] = 0;
        }

        foreach ($input->workstations as $workstation) {
            $free = new FreeTime($workstation->intervals);
            $free->remove($outside);
            $this->workstations[$workstation->id] = $free;
            $this->bookedWorkstation[$workstation->id] = 0;
        }
    }

    /**
     * A locked operation keeps its person, workstation and start; when the
     * person or the workstation is not free at that start (absent, down),
     * the lock is broken and the operation is planned as any other.
     *
     * @return array{0: int, 1: int}|null
     */
    private function placeLocked(PlanOrder $order, PlanOperation $operation): ?array
    {
        $pin = $operation->pin;
        $start = (int) $pin?->start;
        $person = $this->people[(int) $pin?->employeeId] ?? null;

        if ($pin === null || $person === null || ! $person->isFreeAt($start)) {
            return null;
        }

        $workstationId = $pin->workstationId;

        if ($workstationId === null && $operation->workstationIds !== []) {
            foreach ($operation->workstationIds as $candidate) {
                if (($this->workstations[$candidate] ?? null)?->isFreeAt($start)) {
                    $workstationId = $candidate;

                    break;
                }
            }
        }

        $workstation = $workstationId === null ? null : ($this->workstations[$workstationId] ?? null);

        if (($operation->workstationIds !== [] || $workstationId !== null) && ($workstation === null || ! $workstation->isFreeAt($start))) {
            return null;
        }

        $fill = $person->fill($start, $operation->minutes, $workstation);

        if ($fill === null) {
            return null;
        }

        $this->book($order, $operation, $pin->employeeId, $workstationId, $fill, locked: true);

        return [$fill->start, $fill->end];
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $done
     */
    private function planOrder(PlanInput $input, PlanOrder $order, array $done, bool $lockBroken): PlanForecast
    {
        $earliest = max($input->now, $order->earliestStart ?? $input->now);
        $cursor = $earliest;
        $first = null;
        $last = null;
        $beyond = false;
        $noResource = false;
        $outOfOrder = false;

        foreach ($order->operations as $operation) {
            if (isset($done[$operation->id])) {
                [$start, $end] = $done[$operation->id];
                // Locked work that starts before the work before it can be finished.
                $outOfOrder = $outOfOrder || ($last !== null && $start < $cursor);
                $first = $first === null ? $start : min($first, $start);
                $last = max($last ?? $end, $end);
                $cursor = max($cursor, $end + $operation->waitMinutes);

                continue;
            }

            if ($operation->minutes <= 0) {
                continue;
            }

            $placed = $this->place($order, $operation, $cursor);

            if (! is_array($placed)) {
                $beyond = true;
                $noResource = $placed === false;

                break;
            }

            [$start, $end] = $placed;
            $first = $first === null ? $start : min($first, $start);
            $last = max($last ?? $end, $end);
            $cursor = $end + $operation->waitMinutes;
        }

        $finish = $beyond ? null : ($last === null ? $input->now : $cursor);
        $delivery = $finish === null ? null : $input->calendar->deliveryDate($finish, $input->deliveryBufferDays);

        $flags = array_values(array_filter([
            $order->materialAssumed ? PlanForecast::ASSUMED_MATERIAL : null,
            $beyond ? PlanForecast::BEYOND_HORIZON : null,
            $noResource ? PlanForecast::NO_RESOURCE : null,
            $lockBroken ? PlanForecast::LOCK_BROKEN : null,
            $outOfOrder ? PlanForecast::LOCK_OUT_OF_ORDER : null,
        ]));

        return new PlanForecast(
            $order->id,
            $order->salesDocumentId,
            $order->materialReadyOn,
            $first,
            $finish,
            $delivery,
            $order->promisedOn,
            self::status($input->calendar, $delivery, $order->promisedOn, $order->materialAssumed),
            self::waitsFor($input, $order, $earliest, $first, $beyond),
            $flags,
        );
    }

    /**
     * Places one operation from the cursor: on the person it is pinned to,
     * or on the qualified candidate that finishes first; a split puts its
     * part on the second person in parallel.
     *
     * @return array{0: int, 1: int}|false|null first and last minute; null past the horizon, false when nobody may do it
     */
    private function place(PlanOrder $order, PlanOperation $operation, int $cursor): array|false|null
    {
        $split = $operation->split;
        $minutes = $operation->minutes;
        $splitBooking = null;
        $start = null;
        $end = null;

        if ($split !== null && $split->minutes > 0 && isset($this->people[$split->employeeId])) {
            $part = min($minutes, $split->minutes);
            $best = $this->best($operation, [$split->employeeId], $cursor, $part);

            if (! is_array($best)) {
                return $best;
            }

            $this->book($order, $operation, $best[0], $best[1], $best[2], locked: false, part: 1);
            $splitBooking = $best;
            [$start, $end] = [$best[2]->start, $best[2]->end];
            $minutes -= $part;
        }

        if ($minutes > 0) {
            $exclude = $split?->employeeId;
            $candidates = $operation->pin !== null && ! $operation->pin->isLock()
                ? [$operation->pin->employeeId]
                : array_values(array_filter(array_keys($this->people), fn (int $id): bool => $id !== $exclude && $this->persons[$id]->has($operation->skillId)));

            $best = $this->best($operation, $candidates, $cursor, $minutes);

            if (! is_array($best)) {
                // The split part alone does not finish the operation: give its time back.
                if ($splitBooking !== null) {
                    $this->unbook($splitBooking[0], $splitBooking[1], $splitBooking[2]);
                }

                return $best;
            }

            $this->book($order, $operation, $best[0], $best[1], $best[2], locked: false);
            $start = $start === null ? $best[2]->start : min($start, $best[2]->start);
            $end = max($end ?? $best[2]->end, $best[2]->end);
        }

        return $start === null ? null : [$start, (int) $end];
    }

    /**
     * @param  list<int>  $candidates
     * @return array{0: int, 1: ?int, 2: Fill}|false|null person, workstation, where; null past the horizon, false when nobody may do it
     */
    private function best(PlanOperation $operation, array $candidates, int $cursor, int $minutes): array|false|null
    {
        $workstations = $operation->workstationIds === []
            ? [null]
            : array_values(array_filter($operation->workstationIds, fn (int $id): bool => isset($this->workstations[$id])));

        $candidates = array_values(array_filter($candidates, fn (int $id): bool => isset($this->people[$id])));

        if ($candidates === [] || $workstations === []) {
            return false;
        }

        $best = null;
        $bestKey = null;

        foreach ($candidates as $personId) {
            foreach ($workstations as $workstationId) {
                $fill = $this->people[$personId]->fill($cursor, $minutes, $workstationId === null ? null : $this->workstations[$workstationId]);

                if ($fill === null) {
                    continue;
                }

                $key = [$fill->end, $this->bookedPerson[$personId], $personId, $workstationId === null ? 0 : $this->bookedWorkstation[$workstationId], $workstationId ?? 0];

                if ($bestKey === null || $key < $bestKey) {
                    $best = [$personId, $workstationId, $fill];
                    $bestKey = $key;
                }
            }
        }

        return $best;
    }

    private function book(PlanOrder $order, PlanOperation $operation, int $personId, ?int $workstationId, Fill $fill, bool $locked, int $part = 0): void
    {
        $this->people[$personId]->book($fill->segments);
        $this->bookedPerson[$personId] += $fill->minutes;

        if ($workstationId !== null && isset($this->workstations[$workstationId])) {
            $this->workstations[$workstationId]->book($fill->segments);
            $this->bookedWorkstation[$workstationId] += $fill->minutes;
        }

        $this->slots[] = new PlanSlot($order->id, $operation->id, $personId, $workstationId, $fill->start, $fill->end, $fill->minutes, $fill->segments, $locked, $part);
    }

    private function unbook(int $personId, ?int $workstationId, Fill $fill): void
    {
        $this->people[$personId]->add($fill->segments);
        $this->bookedPerson[$personId] -= $fill->minutes;

        if ($workstationId !== null && isset($this->workstations[$workstationId])) {
            $this->workstations[$workstationId]->add($fill->segments);
            $this->bookedWorkstation[$workstationId] -= $fill->minutes;
        }

        array_pop($this->slots);
    }

    public static function status(WorkingCalendar $calendar, ?string $delivery, ?string $promised, bool $assumedMaterial): ForecastStatus
    {
        return match (true) {
            $promised === null => ForecastStatus::Grey,
            $delivery === null, $delivery > $promised => ForecastStatus::Red,
            $delivery === $promised, $assumedMaterial => ForecastStatus::Yellow,
            $calendar->workingDaysBetween($delivery, $promised) >= 1 => ForecastStatus::Green,
            default => ForecastStatus::Yellow,
        };
    }

    private static function waitsFor(PlanInput $input, PlanOrder $order, int $earliest, ?int $first, bool $beyond): WaitsFor
    {
        $today = Clock::date($input->now);

        if ($order->materialReadyOn !== null && $order->materialReadyOn > $today) {
            return WaitsFor::Material;
        }

        if ($first === null) {
            return $beyond ? WaitsFor::Capacity : WaitsFor::Nothing;
        }

        return Clock::date($first) > Clock::date($input->calendar->nextStart($earliest)) ? WaitsFor::Capacity : WaitsFor::Nothing;
    }
}
