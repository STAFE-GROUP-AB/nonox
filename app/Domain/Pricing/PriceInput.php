<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Modules\Inventory\Domain\Quantity;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Reads what a person types on the price screens (ADR-082): percentages as
 * signed basis points, amounts in a list's currency as minor units, and
 * quantities as thousandths. Nothing here uses floats.
 */
final class PriceInput
{
    /** "12", "-12,5", "+3.25" as basis points; null when it is not a percentage with at most two decimals. */
    public static function percentToBasisPoints(string $input): ?int
    {
        $text = str_replace([',', ' ', "\u{00A0}", "\u{2212}", '%'], ['.', '', '', '-', ''], trim($input));

        if (preg_match('/^[+-]?\d{1,4}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }

        try {
            return BigDecimal::of(ltrim($text, '+'))->multipliedBy(100)->toScale(0)->toInt();
        } catch (MathException) {
            return null;
        }
    }

    /** Basis points as Swedish percent text for a form: 1250 is "12,5", -1200 is "-12". */
    public static function basisPointsToPercent(int $basisPoints): string
    {
        $sign = $basisPoints < 0 ? '-' : '';
        $absolute = abs($basisPoints);
        $fraction = rtrim(str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($absolute, 100).($fraction === '' ? '' : ",{$fraction}");
    }

    /** An amount in the list's currency as minor units; null when it cannot be read or is negative. */
    public static function minor(string $input, string $currency): ?int
    {
        try {
            $money = Money::parse($input, $currency);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $money->isNegative() ? null : $money->ore;
    }

    /** A quantity as thousandths; null when it cannot be read or is negative. Empty means from the first unit. */
    public static function quantityMilli(string $input): ?int
    {
        if (trim($input) === '') {
            return 0;
        }

        try {
            $milli = Quantity::parse($input);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $milli < 0 ? null : $milli;
    }
}
