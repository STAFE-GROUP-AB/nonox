<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/** Part of an operation given to a second person by a planner, worked in parallel (ADR-071 §13.3 step 4). */
final readonly class PlanSplit
{
    public function __construct(
        public int $lockId,
        public int $employeeId,
        public int $minutes,
    ) {}
}
