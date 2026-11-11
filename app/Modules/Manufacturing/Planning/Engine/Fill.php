<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Planning\Engine;

/**
 * Where an operation would go on a resource: the stretches of free time
 * it fills, from its first minute to its last.
 */
final readonly class Fill
{
    /**
     * @param  list<array{0: int, 1: int}>  $segments
     */
    public function __construct(
        public array $segments,
        public int $start,
        public int $end,
        public int $minutes,
    ) {}
}
