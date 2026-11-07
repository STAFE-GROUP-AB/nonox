<?php

declare(strict_types=1);

namespace App\Support\Portability;

use App\Support\Luhn;

/**
 * Tells a personal number (personnummer, samordningsnummer) from a company's
 * organisation number. Both have ten digits and a Luhn check digit, but an
 * organisation number's third digit is 2 or more, while a personal number has
 * a month there (01-12, 61-72 for a coordination number's day does not touch
 * this digit).
 */
final class PersonalNumber
{
    public static function looksLike(?string $value): bool
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        if (strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) === 10
            && in_array($digits[2], ['0', '1'], true)
            && Luhn::isValid($digits);
    }
}
