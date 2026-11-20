<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Mass price updates (ADR-082 §8): seen with prices:view, planned, applied
 * and reverted with price-lists:manage, the same key as the lists and base
 * prices they change.
 */
class PriceUpdateRunPolicy extends PricingPolicy
{
    protected function managePermission(): string
    {
        return 'price-lists:manage';
    }
}
