<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * GTIN (EAN-8, UPC-A/GTIN-12, EAN-13, GTIN-14): digits with a mod-10 check
 * digit weighted 3 and 1 from the right, as GS1 specifies.
 */
final class Gtin
{
    public static function isValid(string $code): bool
    {
        if (preg_match('/^\d+$/', $code) !== 1 || ! in_array(strlen($code), [8, 12, 13, 14], true)) {
            return false;
        }

        return self::checkDigit(substr($code, 0, -1)) === (int) substr($code, -1);
    }

    /** The check digit for the digits before it. */
    public static function checkDigit(string $body): int
    {
        $sum = 0;
        $digits = array_reverse(str_split($body));

        foreach ($digits as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }
}
