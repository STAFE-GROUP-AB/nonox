<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * What a price below the floor does on a price agreement (ADR-082 §9): a
 * warning only, or an approval by someone with prices:approve-below-floor.
 */
enum PriceFloorPolicy: string
{
    case Warn = 'WARN';
    case RequireApproval = 'REQUIRE_APPROVAL';
}
