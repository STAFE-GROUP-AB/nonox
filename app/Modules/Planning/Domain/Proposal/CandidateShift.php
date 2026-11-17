<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use Carbon\CarbonImmutable;

/** A shift the employee already has (draft or published), for the rules and the fairness window. */
final readonly class CandidateShift
{
    public function __construct(
        public string $id,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $breakMinutes = 0,
        public ?int $areaId = null,
    ) {}

    public function workedMinutes(): int
    {
        return max(0, intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60) - $this->breakMinutes);
    }
}
