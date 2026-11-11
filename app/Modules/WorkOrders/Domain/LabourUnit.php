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

    /** The unit of the company's register an invoice row for this kind is in (ADR-071 §12.9); fixed, whatever the language. */
    public function registerCode(): string
    {
        return match ($this) {
            self::Hour => 'h',
            self::Trip => 'resa',
            self::Km => 'km',
        };
    }

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
