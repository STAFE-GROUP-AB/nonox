<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Where the price on a document row came from (ADR-082 §5), stored on the
 * row as `price_source` next to its structured explanation. The resolver
 * (P2) tries the sources in this order; a manual price is never replaced.
 */
enum PriceSource: string
{
    case Manual = 'MANUAL';
    case Agreement = 'AGREEMENT';
    case CustomerList = 'CUSTOMER_LIST';
    case Campaign = 'CAMPAIGN';
    case CurrencyList = 'CURRENCY_LIST';
    case Base = 'BASE';
    case Converted = 'CONVERTED';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manuellt pris'),
            self::Agreement => __('Prisavtal'),
            self::CustomerList => __('Kundens prislista'),
            self::Campaign => __('Kampanj'),
            self::CurrencyList => __('Valutans prislista'),
            self::Base => __('Grundpris'),
            self::Converted => __('Omräknat grundpris'),
        };
    }
}
