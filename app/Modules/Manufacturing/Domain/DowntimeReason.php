<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/** Why a workstation is down (ADR-071 §13.2). */
enum DowntimeReason: string
{
    case Breakdown = 'BREAKDOWN';
    case Maintenance = 'MAINTENANCE';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Breakdown => __('Haveri'),
            self::Maintenance => __('Underhåll'),
            self::Other => __('Annat'),
        };
    }
}
