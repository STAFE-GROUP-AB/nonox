<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * What a seller's monthly goal counts (ADR-043 stage 2), always measured
 * from data: completed meetings assigned to the seller, quotes the seller
 * created in the ledger, and the value of the deals the seller won.
 */
enum GoalMetric: string
{
    case Meetings = 'MEETINGS';
    case Quotes = 'QUOTES';
    case OrderValue = 'ORDER_VALUE';

    public function label(): string
    {
        return match ($this) {
            self::Meetings => __('Möten'),
            self::Quotes => __('Offerter'),
            self::OrderValue => __('Ordervärde'),
        };
    }

    /** Order value is money, in öre; the others are counts. */
    public function isMoney(): bool
    {
        return $this === self::OrderValue;
    }
}
