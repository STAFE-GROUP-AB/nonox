<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

use App\Modules\StaffLedger\Models\Entry;
use Carbon\CarbonImmutable;

/**
 * A work shift read from the ledger: a check-in and the check-out that
 * follows it at the same location. Either side may be missing.
 */
final readonly class Shift
{
    public function __construct(
        public int $locationId,
        public ?int $personId,
        public string $personName,
        public string $personalNumber,
        public ?string $employerName,
        public ?string $employerOrgNumber,
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public ?Entry $startEntry,
        public ?Entry $endEntry,
    ) {}

    public function isOpen(): bool
    {
        return $this->start !== null && $this->end === null;
    }

    public function hours(?CarbonImmutable $now = null): ?float
    {
        if ($this->start === null) {
            return null;
        }

        $end = $this->end ?? $now;

        return $end === null ? null : round($this->start->diffInMinutes($end) / 60, 2);
    }

    public function corrected(): bool
    {
        return ($this->startEntry !== null && $this->startEntry->type !== EntryType::CheckIn)
            || ($this->endEntry !== null && $this->endEntry->type !== EntryType::CheckOut);
    }
}
