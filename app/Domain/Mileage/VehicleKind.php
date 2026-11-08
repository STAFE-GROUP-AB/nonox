<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

/**
 * A private car is owned (or privately leased) by the person who drives it;
 * a company car belongs to the company, either as someone's förmånsbil
 * (Lön → Förmånsbilar) or as a pool car without a benefit.
 */
enum VehicleKind: string
{
    case PrivateCar = 'PRIVATE_CAR';
    case CompanyCar = 'COMPANY_CAR';

    public function label(): string
    {
        return match ($this) {
            self::PrivateCar => __('Egen bil'),
            self::CompanyCar => __('Företagets bil'),
        };
    }
}
