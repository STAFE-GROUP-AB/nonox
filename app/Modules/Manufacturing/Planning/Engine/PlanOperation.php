<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/**
 * An operation of a production order as the scheduler sees it: the minutes
 * that remain, the skill it needs, the workstations it may use (none means
 * it needs none), and the waiting time after it (drying, curing).
 */
final readonly class PlanOperation
{
    /**
     * @param  list<int>  $workstationIds
     */
    public function __construct(
        public int $id,
        public int $operationId,
        public int $minutes,
        public ?int $skillId = null,
        public array $workstationIds = [],
        public int $waitMinutes = 0,
        public ?PlanPin $pin = null,
        public ?PlanSplit $split = null,
    ) {}

    public function with(int $minutes): self
    {
        return new self($this->id, $this->operationId, $minutes, $this->skillId, $this->workstationIds, $this->waitMinutes, $this->pin, $this->split);
    }
}
