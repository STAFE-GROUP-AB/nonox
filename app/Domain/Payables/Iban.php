<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/**
 * Checks an IBAN: the country's length (ISO 13616 registry), the check
 * digits (mod 97-10, ISO 7064) and the characters. A payment abroad goes to
 * the IBAN alone, so a mistyped one must never reach a payment file.
 */
final class Iban
{
    /**
     * IBAN length per country, for the countries payments are likely to go to.
     * TODO(provider-verify): extend from the SWIFT IBAN registry when a
     * supplier in another country needs it; an unlisted country is refused.
     *
     * @var array<string, int>
     */
    private const array LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22,
        'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28,
        'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23,
        'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26,
        'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15, 'PK' => 24,
        'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'SA' => 24, 'SE' => 24, 'SI' => 19,
        'SK' => 24, 'SM' => 27, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    /** The IBAN without spaces, in capitals. */
    public static function normalise(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /** The two-letter country the IBAN starts with, or null when it does not start with one. */
    public static function country(string $iban): ?string
    {
        $iban = self::normalise($iban);

        return preg_match('/^[A-Z]{2}/', $iban) === 1 ? substr($iban, 0, 2) : null;
    }

    /**
     * Why the IBAN is wrong, as a Swedish sentence, or null when it is right.
     */
    public static function problem(string $iban): ?string
    {
        $iban = self::normalise($iban);

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban) !== 1) {
            return __('IBAN ska börja med landskoden och två kontrollsiffror, till exempel SE45 5000 0000 0583 9825 7466.');
        }

        $length = self::LENGTHS[substr($iban, 0, 2)] ?? null;

        if ($length === null) {
            return __('IBAN från landet :country stöds inte för betalfiler ännu. Betala i internetbanken.', ['country' => substr($iban, 0, 2)]);
        }

        if (strlen($iban) !== $length) {
            return __('IBAN för :country ska vara :length tecken men är :actual. Kontrollera numret.', ['country' => substr($iban, 0, 2), 'length' => $length, 'actual' => strlen($iban)]);
        }

        if (self::mod97(substr($iban, 4).substr($iban, 0, 4)) !== 1) {
            return __('IBAN:s kontrollsiffror stämmer inte. Kontrollera numret.');
        }

        return null;
    }

    public static function isValid(string $iban): bool
    {
        return self::problem($iban) === null;
    }

    /** Remainder of the digit string (letters A=10 … Z=35) divided by 97, in steps so no big number is needed. */
    private static function mod97(string $rearranged): int
    {
        $remainder = 0;

        foreach (str_split($rearranged) as $character) {
            $digits = ctype_digit($character) ? $character : (string) (ord($character) - 55);
            $remainder = ((int) ($remainder.$digits)) % 97;
        }

        return $remainder;
    }
}
