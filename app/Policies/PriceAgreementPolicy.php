<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Customer price agreements, "Prisavtal" (ADR-082 §4): seen with
 * prices:view, made, sent and terminated with price-agreements:manage, which
 * the CRM seller also has while CRM is on. Freezing an active agreement's
 * terms is added with the agreement flow (P5).
 */
class PriceAgreementPolicy extends PricingPolicy
{
    protected function managePermission(): string
    {
        return 'price-agreements:manage';
    }
}
