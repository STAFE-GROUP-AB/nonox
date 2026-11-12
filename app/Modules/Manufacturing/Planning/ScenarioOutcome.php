<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

use App\Modules\Manufacturing\Domain\ForecastStatus;
use App\Modules\Manufacturing\Planning\Engine\PlanResult;
use App\Modules\Manufacturing\Planning\Engine\WorkingCalendar;

/**
 * What a scenario does to every production order (ADR-071 §13.6): the
 * forecast delivery without and with the changes, computed at the same
 * moment, and the difference in working days.
 */
final readonly class ScenarioOutcome
{
    /**
     * @param  array<int, array{before: ?string, after: ?string, change: ?int, status: ForecastStatus}>  $orders
     */
    public function __construct(public array $orders) {}

    public static function compare(PlanResult $before, PlanResult $after, WorkingCalendar $calendar): self
    {
        $orders = [];

        foreach ($after->forecasts as $orderId => $forecast) {
            $was = $before->forecast($orderId)?->deliveryOn;
            $now = $forecast->deliveryOn;

            $orders[$orderId] = [
                'before' => $was,
                'after' => $now,
                'change' => $was === null || $now === null ? null : $calendar->workingDaysBetween($was, $now),
                'status' => $forecast->status,
            ];
        }

        return new self($orders);
    }

    public function change(int $orderId): ?int
    {
        return $this->orders[$orderId]['change'] ?? null;
    }

    /** @return array<int, int> orders the scenario makes later, by working days */
    public function delayed(): array
    {
        return array_filter(array_map(fn (array $order): int => (int) $order['change'], $this->orders), fn (int $change): bool => $change > 0);
    }
}
