<?php

declare(strict_types=1);

namespace App\Domain\Status;

/** How an incident on the public status page counts towards the overall state (ADR-078). */
enum IncidentSeverity: string
{
    case Info = 'INFO';
    case Maintenance = 'MAINTENANCE';
    case Degraded = 'DEGRADED';
    case Outage = 'OUTAGE';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::Maintenance => 'Planerat underhåll',
            self::Degraded => 'Störning',
            self::Outage => 'Avbrott',
        };
    }

    /** The state an open incident of this severity sets on the page. */
    public function state(): ?ComponentState
    {
        return match ($this) {
            self::Info => null,
            self::Maintenance => ComponentState::Maintenance,
            self::Degraded => ComponentState::Degraded,
            self::Outage => ComponentState::Outage,
        };
    }
}
