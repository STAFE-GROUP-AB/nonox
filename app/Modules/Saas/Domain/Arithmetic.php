<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * Integer arithmetic for the metrics: division half up (AGENTS.md §6.3) and
 * conversion of minor units to öre at a rate in millionths, the same way as
 * App\Support\Currency\ExchangeRate::toOre.
 */
final class Arithmetic
{
    public const int SCALE = 1_000_000;

    public static function divideHalfUp(int $numerator, int $denominator): int
    {
        $sign = ($numerator < 0) !== ($denominator < 0) ? -1 : 1;
        $numerator = abs($numerator);
        $denominator = abs($denominator);

        return $sign * intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /** Minor units of a two-decimal currency to öre; SEK is passed through. */
    public static function toOre(int $minor, string $currency, ?int $micros): int
    {
        if ($currency === 'SEK') {
            return $minor;
        }

        return self::divideHalfUp($minor * (int) $micros, self::SCALE);
    }

    /** A share as a percentage with one decimal, or null without a base: "92,5 %". */
    public static function percent(?int $numerator, ?int $denominator): ?string
    {
        if ($numerator === null || $denominator === null || $denominator === 0) {
            return null;
        }

        $tenths = self::divideHalfUp($numerator * 1000, $denominator);
        $sign = $tenths < 0 ? '−' : '';
        $tenths = abs($tenths);

        return $sign.intdiv($tenths, 10).','.($tenths % 10)."\u{00A0}%";
    }
}
