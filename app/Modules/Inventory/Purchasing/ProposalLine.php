<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Models\Article;
use App\Models\Unit;

/** One article in the purchase proposal (ADR-071 §5); quantities in milli-units of the stock unit. */
final readonly class ProposalLine
{
    /**
     * @param  array<string, int>  $reservedBy  demand source label => reserved milli
     */
    public function __construct(
        public Article $article,
        public int $onHandMilli,
        public int $reservedMilli,
        public array $reservedBy,
        public int $comingMilli,
        public ?string $comingFrom,
        public int $shortMilli,
        public bool $belowReorderPoint,
        /** What the proposal orders, in the stock unit. */
        public int $proposedMilli,
        /** The same in the purchase unit, rounded up (whole units for counted units and packs). */
        public string $proposedQuantity,
        public Unit $unit,
    ) {}

    public function freeMilli(): int
    {
        return $this->onHandMilli - $this->reservedMilli;
    }
}
