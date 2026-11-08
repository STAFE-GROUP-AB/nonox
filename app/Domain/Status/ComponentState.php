<?php

declare(strict_types=1);

namespace App\Domain\Status;

/** The state of one part of the service, as shown on the public status page (ADR-078). */
enum ComponentState: string
{
    /** Not measured, or not set up on this installation: never counts towards the overall state. */
    case Unknown = 'UNKNOWN';
    case Ok = 'OK';
    case Maintenance = 'MAINTENANCE';
    case Degraded = 'DEGRADED';
    case Outage = 'OUTAGE';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Inte mätt',
            self::Ok => 'I drift',
            self::Maintenance => 'Underhåll',
            self::Degraded => 'Störning',
            self::Outage => 'Avbrott',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Unknown, self::Ok => 0,
            self::Maintenance => 1,
            self::Degraded => 2,
            self::Outage => 3,
        };
    }

    public function worst(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }
}
