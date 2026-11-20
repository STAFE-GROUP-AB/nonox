<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * One row to price (ADR-082 §5): the article, the row's unit (null = the
 * article's sales unit) and its quantity in milli-units of that unit. A row
 * whose price the user typed carries it as $manualPriceMinor and is never
 * re-priced; a discount the user typed ($manualDiscountBp) is laid on top of
 * whatever price the row gets (decision 8). $key is the caller's own row key,
 * returned unchanged on the result.
 */
final readonly class PriceRequest
{
    public function __construct(
        public string $key,
        public int $articleId,
        public int $quantityMilli = 1000,
        public ?int $unitId = null,
        public ?int $manualPriceMinor = null,
        public ?int $manualDiscountBp = null,
    ) {
        if ($manualPriceMinor !== null && $manualPriceMinor < 0) {
            throw new InvalidArgumentException('A manual price cannot be negative.');
        }

        if ($manualDiscountBp !== null && ($manualDiscountBp < 0 || $manualDiscountBp > 10000)) {
            throw new InvalidArgumentException('A manual discount is between 0 and 10000 basis points.');
        }
    }
}
