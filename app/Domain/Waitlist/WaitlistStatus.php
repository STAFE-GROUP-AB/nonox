<?php

declare(strict_types=1);

namespace App\Domain\Waitlist;

/** Where an entry on the waiting list stands (ADR-038). */
enum WaitlistStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Parked = 'PARKED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('I kö'),
            self::Approved => __('Godkänd'),
            self::Rejected => __('Nekad'),
            self::Parked => __('Parkerad'),
        };
    }

    /** Whether a decision can still be made: approved entries are final. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Parked;
    }
}
