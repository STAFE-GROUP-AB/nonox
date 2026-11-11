<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/** A workstation in the plan: the time it is not down. */
final readonly class PlanWorkstation
{
    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    public function __construct(
        public int $id,
        public array $intervals,
    ) {}
}
