<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Price lists, campaigns and channel lists (ADR-082 §2): seen with
 * prices:view, made and changed with price-lists:manage.
 */
class PriceListPolicy extends PricingPolicy
{
    protected function managePermission(): string
    {
        return 'price-lists:manage';
    }
}
