<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/** A computed plan: the slots, a forecast per production order, and the locks that could not be kept. */
final readonly class PlanResult
{
    /**
     * @param  list<PlanSlot>  $slots
     * @param  array<int, PlanForecast>  $forecasts  by production order id
     * @param  list<int>  $brokenLocks  lock ids
     */
    public function __construct(
        public array $slots,
        public array $forecasts,
        public array $brokenLocks = [],
    ) {}

    public function forecast(int $orderId): ?PlanForecast
    {
        return $this->forecasts[$orderId] ?? null;
    }
}
