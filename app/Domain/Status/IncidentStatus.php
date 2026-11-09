<?php

declare(strict_types=1);

namespace App\Domain\Status;

enum IncidentStatus: string
{
    case Investigating = 'INVESTIGATING';
    case Identified = 'IDENTIFIED';
    case Monitoring = 'MONITORING';
    case Resolved = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::Investigating => 'Utreds',
            self::Identified => 'Orsak funnen',
            self::Monitoring => 'Övervakas',
            self::Resolved => 'Åtgärdad',
        };
    }
}
