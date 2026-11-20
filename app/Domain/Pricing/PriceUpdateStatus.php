<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * A mass price update (ADR-082 §8) is previewed, then applied at once or
 * scheduled for its effective date, and can be reverted until that date.
 */
enum PriceUpdateStatus: string
{
    case Preview = 'PREVIEW';
    case Applied = 'APPLIED';
    case Scheduled = 'SCHEDULED';
    case Reverted = 'REVERTED';
}
