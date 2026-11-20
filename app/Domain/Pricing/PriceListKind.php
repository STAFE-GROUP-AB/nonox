<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * What a price list is for (ADR-082 §2): a customer group's or a currency's
 * prices, time-limited campaign prices, or the prices of a sales channel
 * (the till or the portal assortment).
 */
enum PriceListKind: string
{
    case Standard = 'STANDARD';
    case Campaign = 'CAMPAIGN';
    case Channel = 'CHANNEL';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('Prislista'),
            self::Campaign => __('Kampanj'),
            self::Channel => __('Kanalprislista'),
        };
    }
}
