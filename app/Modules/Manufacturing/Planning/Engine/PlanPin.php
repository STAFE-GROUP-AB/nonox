<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/**
 * A planner's hold on an operation (ADR-071 §13.6): a lock with a start
 * keeps person, workstation and start as they are; without a start it
 * only keeps the person (a move to another person).
 */
final readonly class PlanPin
{
    public function __construct(
        public int $lockId,
        public int $employeeId,
        public ?int $workstationId = null,
        public ?int $start = null,
    ) {}

    public function isLock(): bool
    {
        return $this->start !== null;
    }
}
