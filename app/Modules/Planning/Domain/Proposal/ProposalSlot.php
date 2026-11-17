<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use Carbon\CarbonImmutable;

/**
 * An existing open shift the engine may fill. Who said the time does not
 * suit them, who has approved absence on a day the shift touches and who
 * would rather work then are worked out by the loader with the schedule's
 * own rules (RequestChecks, AbsenceDays) and passed in as employee ids.
 */
final readonly class ProposalSlot
{
    /**
     * @param  list<int>  $unavailable
     * @param  list<int>  $absent
     * @param  list<int>  $preferred
     */
    public function __construct(
        public string $id,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $breakMinutes = 0,
        public ?int $areaId = null,
        public ?int $skillId = null,
        public array $unavailable = [],
        public array $absent = [],
        public array $preferred = [],
    ) {}

    public function workedMinutes(): int
    {
        return max(0, intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60) - $this->breakMinutes);
    }
}
