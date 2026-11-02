<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Stock quantities are whole thousandths of the article's unit (milli), so
 * 1,5 kg is 1500 and 3 st is 3000: integers all the way, never floats. The
 * value of a quantity is quantity × unit cost / 1000, rounded half up to
 * whole öre.
 */
final class Quantity
{
    public const int SCALE = 1000;

    /** Parses "3", "1,5", "1 234,250" or "1.5" into thousandths. */
    public static function parse(string|int|float $input): int
    {
        if (is_int($input)) {
            return $input * self::SCALE;
        }

        $text = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string) $input));
        $text = str_replace(',', '.', $text);

        if (preg_match('/^-?\d+(\.\d{1,3})?$/', $text) !== 1) {
            throw new InvalidArgumentException("Not a quantity: {$input}");
        }

        return BigDecimal::of($text)->multipliedBy(self::SCALE)->toScale(0)->toInt();
    }

    /** Thousandths as Swedish text without trailing zeros: 1500 → "1,5". */
    public static function format(int $milli): string
    {
        $sign = $milli < 0 ? '−' : '';
        $milli = abs($milli);
        $whole = intdiv($milli, self::SCALE);
        $fraction = rtrim(str_pad((string) ($milli % self::SCALE), 3, '0', STR_PAD_LEFT), '0');
        $wholeText = number_format($whole, 0, ',', "\u{00A0}");

        return $sign.$wholeText.($fraction === '' ? '' : ",{$fraction}");
    }

    /** Thousandths as a plain decimal string for forms and the API: 1500 → "1.5". */
    public static function decimal(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);
        $fraction = rtrim(str_pad((string) ($milli % self::SCALE), 3, '0', STR_PAD_LEFT), '0');

        return $sign.intdiv($milli, self::SCALE).($fraction === '' ? '' : ".{$fraction}");
    }

    /** The value of a quantity at a cost per whole unit, in öre, rounded half up. */
    public static function value(int $milli, int $unitCostOre): int
    {
        return BigDecimal::of($milli)->multipliedBy($unitCostOre)->dividedBy(self::SCALE, 0, RoundingMode::HalfUp)->toInt();
    }
}
