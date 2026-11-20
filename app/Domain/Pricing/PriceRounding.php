<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * How a computed price is rounded (ADR-082 §7): on a derived list, a percent
 * price update and a currency conversion; never on a typed price. All of it
 * is integer arithmetic on minor units (öre, cent), never floats.
 *
 * - None: to the minor unit, i.e. unchanged.
 * - Whole, Half, Tens: to the nearest 1,00, 0,50 or 10,00 with HalfUp
 *   (a half goes away from zero), except that a price above zero never
 *   rounds to zero: it becomes one step instead (0,49 → 1,00 with Whole,
 *   4,99 → 10,00 with Tens; ADR-082 §7, Lead decision 2026-11-20).
 * - TenOre9 and EndsIn9: to the nearest ending at or above, never below:
 *   x,90 for TenOre9 (12,91 → 13,90) and whole units ending in 9 for EndsIn9
 *   (141,00 → 149,00; 149,01 → 159,00).
 *
 * A negative amount is rounded as its size and keeps its sign, so every rule
 * is symmetric around zero; zero stays zero (a free article stays free, it
 * does not become 9 kr). A result outside PHP's integer range throws
 * Brick\Math\Exception\IntegerOverflowException rather than wrapping.
 */
enum PriceRounding: string
{
    case None = 'NONE';
    case Whole = 'WHOLE';
    case Half = 'HALF';
    case TenOre9 = 'TEN_ORE_9';
    case EndsIn9 = 'ENDS_IN_9';
    case Tens = 'TENS';

    public function apply(int $minor): int
    {
        if ($this === self::None || $minor === 0) {
            return $minor;
        }

        $size = BigInteger::of($minor)->abs();

        $rounded = match ($this) {
            self::Whole => self::nearest($size, 100),
            self::Half => self::nearest($size, 50),
            self::Tens => self::nearest($size, 1000),
            self::TenOre9 => self::atOrAbove($size, 100, 90),
            self::EndsIn9 => self::atOrAbove($size, 1000, 900),
        };

        return ($minor < 0 ? $rounded->negated() : $rounded)->toInt();
    }

    public function label(): string
    {
        return match ($this) {
            self::None => __('Ingen avrundning'),
            self::Whole => __('Hela kronor'),
            self::Half => __('Hela och halva kronor'),
            self::TenOre9 => __('Slutar på ,90'),
            self::EndsIn9 => __('Hela kronor som slutar på 9'),
            self::Tens => __('Hela tiotal kronor'),
        };
    }

    /** The nearest multiple of $step, but never zero for a size above zero: a price does not round away. */
    private static function nearest(BigInteger $size, int $step): BigInteger
    {
        $rounded = $size->dividedBy($step, RoundingMode::HalfUp)->multipliedBy($step);

        return $rounded->isZero() ? BigInteger::of($step) : $rounded;
    }

    /** The smallest amount >= $size that leaves $ending when divided by $step. */
    private static function atOrAbove(BigInteger $size, int $step, int $ending): BigInteger
    {
        return $size->minus($ending)->dividedBy($step, RoundingMode::Ceiling)->multipliedBy($step)->plus($ending);
    }
}
