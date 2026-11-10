<?php

declare(strict_types=1);

namespace App\Domain\Units;

use Brick\Math\BigDecimal;

/** The result of turning an entered quantity into the stock unit (ADR-071 §12.4). */
final readonly class QuantityConversion
{
    public function __construct(
        public int $stockMilli,
        /** What the stock quantity is worth back in the entered unit. */
        public BigDecimal $roundTrip,
        /** True when the round trip differs from what was typed by more than 0,5 %. */
        public bool $warns,
        /** The change in percent with two decimals, for the warning text. */
        public string $changePercent,
    ) {}
}
