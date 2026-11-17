<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use Carbon\CarbonImmutable;

/**
 * A new shift a proposal makes from the staffing demand (v2, ADR-037
 * amendment gap 3, P8): its times, break and area, the day it is for, and
 * how many under-staffed hours it covers. No person: the engine proposes who.
 */
final readonly class DemandBlock
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $breakMinutes,
        public ?int $areaId,
        public string $date,
        public int $coveredHours,
    ) {}

    public function workedMinutes(): int
    {
        return max(0, intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60) - $this->breakMinutes);
    }

    public function monday(): string
    {
        return CarbonImmutable::parse($this->date, UnsocialTime::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
    }
}
