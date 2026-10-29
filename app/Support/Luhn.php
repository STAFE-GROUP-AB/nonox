<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Luhn (mod 10) algorithm used for Swedish organisation numbers, personal
 * numbers, OCR references, bankgiro and plusgiro numbers.
 */
final class Luhn
{
    public static function isValid(string $digits): bool
    {
        if (! preg_match('/^\d{2,}$/', $digits)) {
            return false;
        }

        $body = substr($digits, 0, -1);
        $checkDigit = (int) substr($digits, -1);

        return self::checkDigit($body) === $checkDigit;
    }

    public static function checkDigit(string $digits): int
    {
        if (! preg_match('/^\d+$/', $digits)) {
            throw new \InvalidArgumentException('Luhn input must contain digits only.');
        }

        $sum = 0;
        $double = true;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return (10 - ($sum % 10)) % 10;
    }
}
