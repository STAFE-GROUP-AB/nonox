<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Customer price groups (ADR-082 §3) belong with the price lists: seen with
 * prices:view, made and changed with price-lists:manage.
 */
class CustomerPriceGroupPolicy extends PricingPolicy
{
    protected function managePermission(): string
    {
        return 'price-lists:manage';
    }
}
