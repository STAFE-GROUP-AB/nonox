<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * How a kind of work is counted: hours (stored as minutes), trips or
 * kilometres (stored as thousandths) (ADR-074 O7: travel per trip or per km).
 */
enum LabourUnit: string
{
    case Hour = 'HOUR';
    case Trip = 'TRIP';
    case Km = 'KM';

    public function label(): string
    {
        return match ($this) {
            self::Hour => __('tim'),
            self::Trip => __('resa'),
            self::Km => __('km'),
        };
    }

    public function isTime(): bool
    {
        return $this === self::Hour;
    }
}
