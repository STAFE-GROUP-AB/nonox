<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The fixed calculation rules of the structure engine (rule sheet
 * RS-C2 – RS-C7), pure and in integers: thousandths, basis points, öre.
 */
final class StructureMath
{
    public const int BP = 10_000;

    /**
     * RS-C2: the gross quantity of a row from its net quantity and its loss
     * as a share of the gross, rounded half up to whole thousandths:
     * gross = net × 10 000 / (10 000 − loss_bp).
     */
    public static function gross(int $netMilli, int $lossBp): int
    {
        if ($lossBp < 0 || $lossBp >= self::BP) {
            throw new InvalidArgumentException('The loss must be 0–9 999 basis points.');
        }

        return BigDecimal::of($netMilli)->multipliedBy(self::BP)->dividedBy(self::BP - $lossBp, 0, RoundingMode::HalfUp)->toInt();
    }

    /** RS-C4 and RS-C5: batch cost per whole yield unit (a portion, a kg), rounded half up to öre. */
    public static function perUnit(int $batchCostOre, int $yieldMilli): int
    {
        if ($yieldMilli <= 0) {
            throw new InvalidArgumentException('The yield must be above zero.');
        }

        return BigDecimal::of($batchCostOre)->multipliedBy(Quantity::SCALE)->dividedBy($yieldMilli, 0, RoundingMode::HalfUp)->toInt();
    }

    /** RS-C6: a share in basis points, rounded half up; null when the whole is not positive. */
    public static function share(int $part, int $whole): ?int
    {
        if ($whole <= 0) {
            return null;
        }

        return BigDecimal::of($part)->multipliedBy(self::BP)->dividedBy($whole, 0, RoundingMode::HalfUp)->toInt();
    }

    /** A rational quantity rounded half up to whole thousandths (RS-C7, once per ingredient and period). */
    public static function round(BigRational $milli): int
    {
        return $milli->toScale(0, RoundingMode::HalfUp)->toBigInteger()->toInt();
    }
}
