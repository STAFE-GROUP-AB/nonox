<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Article;

/**
 * One row being priced: its article, the code of its unit and its quantity
 * in milli-units of that unit (never negative here).
 *
 * @internal used by PriceBook only
 */
final readonly class PricedRow
{
    public function __construct(
        public Article $article,
        public string $unit,
        public int $quantityMilli,
    ) {}
}
