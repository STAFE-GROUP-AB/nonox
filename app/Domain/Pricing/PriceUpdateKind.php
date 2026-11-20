<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The change a mass price update makes (ADR-082 §8): a percentage, a fixed
 * amount, a margin over cost, prices from a file, or an index adjustment from
 * figures the user types.
 */
enum PriceUpdateKind: string
{
    case Percent = 'PERCENT';
    case Amount = 'AMOUNT';
    case Margin = 'MARGIN';
    case File = 'FILE';
    case Index = 'INDEX';
}
