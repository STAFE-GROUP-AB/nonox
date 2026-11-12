<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/**
 * An operation placed in the plan: who does it, at which workstation, from
 * its first minute to its last, and the stretches of time it fills. A split
 * operation has a second slot (part 1).
 */
final readonly class PlanSlot
{
    /**
     * @param  list<array{0: int, 1: int}>  $segments
     */
    public function __construct(
        public int $orderId,
        public int $orderOperationId,
        public ?int $employeeId,
        public ?int $workstationId,
        public int $start,
        public int $end,
        public int $minutes,
        public array $segments,
        public bool $locked = false,
        public int $part = 0,
    ) {}
}
