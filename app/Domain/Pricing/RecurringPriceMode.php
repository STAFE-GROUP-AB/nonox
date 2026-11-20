<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Whether a recurring invoice keeps its own row prices (today's behaviour,
 * the default) or prices its rows on each run (ADR-082 §6, decision 9).
 */
enum RecurringPriceMode: string
{
    case Fixed = 'FIXED';
    case FollowPrices = 'FOLLOW_PRICES';
}
