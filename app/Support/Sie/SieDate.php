<?php

declare(strict_types=1);

namespace App\Support\Sie;

use Carbon\CarbonImmutable;

/**
 * Dates in SIE are always ÅÅÅÅMMDD (§5.10).
 */
final class SieDate
{
    public static function parse(string $date): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $match) || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return null;
        }

        return CarbonImmutable::create((int) $match[1], (int) $match[2], (int) $match[3])?->startOfDay();
    }

    public static function format(CarbonImmutable $date): string
    {
        return $date->format('Ymd');
    }
}
