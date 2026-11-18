<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * A planned order as the engine proposes it (ADR-081 gap 5), before it is
 * stored: what to order or make, how much, when to release it and when it
 * is needed, the demand it covers and the advice on it.
 */
final readonly class PlannedOrderProposal
{
    /**
     * @param  list<array{kind: string, quantity_milli: int, on: ?string, id: string|int|null, number: ?int, article_id: ?int}>  $pegging
     */
    public function __construct(
        public int $articleId,
        public PlannedOrderKind $kind,
        public int $quantityMilli,
        public string $releaseOn,
        public string $dueOn,
        public ?int $supplierId,
        public array $pegging,
        public ?PlannedOrderException $exception,
    ) {}
}
