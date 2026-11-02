<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Exact conversions of the numbers tills send: quantities as strings or
 * JSON numbers into thousandths, and amounts in currency units into öre,
 * never through float arithmetic.
 */
final class Decimals
{
    /** "1", "0.5", "-2", 1.25 → 1000, 500, -2000, 1250; null when not a number. */
    public static function milli(mixed $value): ?int
    {
        $text = match (true) {
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => (string) json_encode($value),
            is_string($value) => trim($value),
            default => null,
        };

        if ($text === null || preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        try {
            return BigDecimal::of($text)->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            return null;
        }
    }

    /** An amount in currency units (149.5) into öre (14950), half up. */
    public static function ore(mixed $value): ?int
    {
        return Money::fromDecimal($value)?->ore;
    }

    /** An integer amount in minor units, as Zettle sends them ("12500" or 12500). */
    public static function minorUnits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    /** A VAT percentage (25, "12", 6.0) into basis points (2500). */
    public static function vatBasisPoints(mixed $value): ?int
    {
        $text = match (true) {
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => (string) json_encode($value),
            is_string($value) => trim($value),
            default => null,
        };

        if ($text === null || preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        return BigDecimal::of($text)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** Öre into a decimal string in currency units: 16688 → "166.88". */
    public static function units(int $ore): string
    {
        return (string) BigDecimal::ofUnscaledValue($ore, 2);
    }
}
