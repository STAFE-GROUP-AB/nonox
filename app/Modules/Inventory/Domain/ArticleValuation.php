<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * One article's value on a day, from its movements up to and including that
 * day (pure, no database):
 *
 * - FIFO: the goods on hand are the ones received last, so they are valued at
 *   the costs of the latest receipts, walking backwards until the quantity on
 *   hand is covered.
 * - Weighted average (perpetual): every receipt is added at its cost; every
 *   issue takes out quantity × the average at that moment. When the stock
 *   reaches zero or below, the average starts over with the next receipt.
 *
 * Lägsta värdets princip (ÅRL 4 kap. 9 §): when a net realisable value per
 * unit is given and the goods are worth less than their cost, the lower
 * value is used.
 */
final readonly class ArticleValuation
{
    public function __construct(
        public int $quantityMilli,
        public int $costValueOre,
        public int $valueOre,
        public ?int $netRealisableUnitOre,
    ) {}

    /** The cost per whole unit, rounded to öre, or null when nothing is on hand. */
    public function unitCostOre(): ?int
    {
        if ($this->quantityMilli <= 0) {
            return null;
        }

        return BigDecimal::of($this->costValueOre)->multipliedBy(Quantity::SCALE)->dividedBy($this->quantityMilli, 0, RoundingMode::HalfUp)->toInt();
    }

    public function writtenDown(): bool
    {
        return $this->valueOre < $this->costValueOre;
    }

    /**
     * @param  list<array{quantity_milli: int, unit_cost_ore: ?int, type: string}>  $movements  in the order they happened (date, then id)
     * @param  int  $fallbackCostOre  cost per unit for goods in without a cost
     */
    public static function of(array $movements, ValuationMethod $method, ?int $netRealisableUnitOre = null, int $fallbackCostOre = 0): self
    {
        $movements = array_values(array_filter($movements, fn (array $movement): bool => $movement['type'] !== MovementType::Transfer->value));
        $onHand = array_sum(array_column($movements, 'quantity_milli'));

        $cost = match ($method) {
            ValuationMethod::Fifo => self::fifo($movements, $onHand, $fallbackCostOre),
            ValuationMethod::Average => self::average($movements, $fallbackCostOre),
        };

        if ($onHand <= 0) {
            return new self($onHand, 0, 0, $netRealisableUnitOre);
        }

        $value = $cost;

        if ($netRealisableUnitOre !== null) {
            $value = min($cost, Quantity::value($onHand, max(0, $netRealisableUnitOre)));
        }

        return new self($onHand, $cost, $value, $netRealisableUnitOre);
    }

    /** @param  list<array{quantity_milli: int, unit_cost_ore: ?int, type: string}>  $movements */
    private static function fifo(array $movements, int $onHand, int $fallbackCostOre): int
    {
        if ($onHand <= 0) {
            return 0;
        }

        $remaining = $onHand;
        $value = 0;

        foreach (array_reverse($movements) as $movement) {
            if ($movement['quantity_milli'] <= 0) {
                continue;
            }

            $take = min($remaining, $movement['quantity_milli']);
            $value += Quantity::value($take, $movement['unit_cost_ore'] ?? $fallbackCostOre);
            $remaining -= $take;

            if ($remaining === 0) {
                return $value;
            }
        }

        // More on hand than ever came in with a cost: the rest at the fallback cost.
        return $value + Quantity::value($remaining, $fallbackCostOre);
    }

    /** @param  list<array{quantity_milli: int, unit_cost_ore: ?int, type: string}>  $movements */
    private static function average(array $movements, int $fallbackCostOre): int
    {
        $quantity = 0;
        $value = 0;

        foreach ($movements as $movement) {
            $change = $movement['quantity_milli'];

            if ($change > 0) {
                $unit = $movement['unit_cost_ore'] ?? ($quantity > 0 ? null : $fallbackCostOre);

                // Below zero, the receipt first fills the hole; only what is left is on hand.
                if ($quantity < 0) {
                    $quantity += $change;
                    $value = $quantity > 0 ? Quantity::value($quantity, $unit ?? $fallbackCostOre) : 0;

                    continue;
                }

                $value += $unit === null
                    ? BigDecimal::of($value)->multipliedBy($change)->dividedBy($quantity, 0, RoundingMode::HalfUp)->toInt()
                    : Quantity::value($change, $unit);
                $quantity += $change;

                continue;
            }

            if ($quantity > 0) {
                $taken = min(-$change, $quantity);
                $value -= BigDecimal::of($value)->multipliedBy($taken)->dividedBy($quantity, 0, RoundingMode::HalfUp)->toInt();
            }

            $quantity += $change;

            if ($quantity <= 0) {
                $value = 0;
            }
        }

        return $quantity > 0 ? $value : 0;
    }
}
