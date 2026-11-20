<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Where a price is asked for (ADR-082 §5 and §10). A CHANNEL or CAMPAIGN list
 * may be tied to one channel (the till or the portal); the resolver gets the
 * channel of the document it prices.
 */
enum PriceChannel: string
{
    case Sales = 'SALES';
    case Portal = 'PORTAL';
    case Pos = 'POS';
    case Recurring = 'RECURRING';
    case WorkOrder = 'WORK_ORDER';
}
