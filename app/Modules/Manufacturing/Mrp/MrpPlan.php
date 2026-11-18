<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * The engine's answer (ADR-081 gap 5): the planned orders, and the advice
 * on existing orders (move forward, excess).
 */
final readonly class MrpPlan
{
    /**
     * @param  list<PlannedOrderProposal>  $plannedOrders
     * @param  list<array{kind: string, article_id: int, source: string, id: int, number: int, on: string, to: ?string, quantity_milli: int, order_milli: int}>  $messages
     */
    public function __construct(
        public array $plannedOrders,
        public array $messages,
    ) {}

    public function exceptionCount(): int
    {
        return count(array_filter($this->plannedOrders, fn (PlannedOrderProposal $order): bool => $order->exception !== null));
    }
}
