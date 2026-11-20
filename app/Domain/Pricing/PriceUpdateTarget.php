<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/** Which price a line of a mass price update changes: the article's base price or an item in a price list. */
enum PriceUpdateTarget: string
{
    case Base = 'BASE';
    case List = 'LIST';
}
