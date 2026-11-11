<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/**
 * Everything one plan is computed from (ADR-071 §13.3 step 1), loaded by
 * PlanSnapshot or built by hand in tests: the moment the plan is made,
 * the horizon, the company's working days, the people, the workstations
 * and the open production orders.
 */
final readonly class PlanInput
{
    /**
     * @param  list<PlanPerson>  $people
     * @param  list<PlanWorkstation>  $workstations
     * @param  list<PlanOrder>  $orders
     */
    public function __construct(
        public int $now,
        public int $horizonEnd,
        public WorkingCalendar $calendar,
        public array $people,
        public array $workstations,
        public array $orders,
        public int $deliveryBufferDays = 0,
    ) {}

    /**
     * @param  list<PlanPerson>|null  $people
     * @param  list<PlanWorkstation>|null  $workstations
     * @param  list<PlanOrder>|null  $orders
     */
    public function with(?array $people = null, ?array $workstations = null, ?array $orders = null): self
    {
        return new self($this->now, $this->horizonEnd, $this->calendar, $people ?? $this->people, $workstations ?? $this->workstations, $orders ?? $this->orders, $this->deliveryBufferDays);
    }
}
