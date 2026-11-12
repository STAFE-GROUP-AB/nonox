<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning;

/**
 * One simulated suggestion for a deviation (ADR-071 §13.5): its kind
 * (MOVE, OVERTIME, SPLIT, PRIORITY, NEW_DATE), the scenario changes that
 * apply it, the facts the screen shows, the most delayed order's forecast
 * without and with it, whether it helps, the other orders it makes later
 * (by working days), and for overtime the estimated labour cost in öre.
 */
final readonly class Suggestion
{
    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $facts
     * @param  array<int, int>  $delayed
     */
    public function __construct(
        public string $kind,
        public array $changes,
        public array $facts,
        public int $orderId,
        public ?string $before,
        public ?string $after,
        public bool $helps,
        public array $delayed,
        public ?int $costOre,
    ) {}
}
