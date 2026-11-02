<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use Carbon\CarbonImmutable;

/**
 * A shift as the working-time checks see it: an id, the interval in
 * Europe/Stockholm and the break.
 */
final readonly class PlannedShift
{
    public function __construct(
        public string $id,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $breakMinutes = 0,
    ) {}

    public function grossMinutes(): int
    {
        return intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60);
    }

    public function workedMinutes(): int
    {
        return max(0, $this->grossMinutes() - $this->breakMinutes);
    }
}
