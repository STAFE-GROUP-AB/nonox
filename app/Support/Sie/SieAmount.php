<?php

declare(strict_types=1);

namespace App\Support\Sie;

/**
 * Amounts in SIE (§5.9): a point as decimal separator, at most two decimals,
 * a minus sign before negative amounts and never a plus sign. Converted to
 * and from whole öre without ever passing through a float.
 */
final class SieAmount
{
    /**
     * At most 16 digits of kronor keeps every amount, and every sum of a
     * realistic number of them, inside a 64-bit integer of öre (§7.6).
     */
    private const MAX_KRONOR_DIGITS = 16;

    public static function toOre(string $amount): ?int
    {
        if (! preg_match('/^(-)?(\d{1,'.self::MAX_KRONOR_DIGITS.'})(?:\.(\d{1,2}))?$/', $amount, $match)) {
            return null;
        }

        $ore = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

        return $match[1] === '-' ? -$ore : $ore;
    }

    public static function format(int $ore): string
    {
        $sign = $ore < 0 ? '-' : '';
        $ore = abs($ore);

        return sprintf('%s%d.%02d', $sign, intdiv($ore, 100), $ore % 100);
    }
}
