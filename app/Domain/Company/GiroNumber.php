<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Support\Luhn;

/**
 * Bankgiro and plusgiro numbers, both protected by a Luhn check digit.
 */
final class GiroNumber
{
    /**
     * Bankgiro: seven or eight digits, written "NNN-NNNN" or "NNNN-NNNN".
     */
    public static function bankgiro(string $input): ?string
    {
        $digits = self::digits($input);

        if (! preg_match('/^\d{7,8}$/', $digits) || ! Luhn::isValid($digits)) {
            return null;
        }

        return substr($digits, 0, -4).'-'.substr($digits, -4);
    }

    /**
     * Plusgiro: two to eight digits, written with the check digit after a
     * hyphen, "NNNNNN-N".
     */
    public static function plusgiro(string $input): ?string
    {
        $digits = self::digits($input);

        if (! preg_match('/^\d{2,8}$/', $digits) || ! Luhn::isValid($digits)) {
            return null;
        }

        return substr($digits, 0, -1).'-'.substr($digits, -1);
    }

    private static function digits(string $input): string
    {
        return preg_replace('/[\s-]/', '', $input) ?? '';
    }
}
