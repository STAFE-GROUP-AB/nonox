<?php

declare(strict_types=1);

namespace App\Support\Pain001;

use Transliterator;

/**
 * Text for a payment abroad, cleaned to the SWIFT Latin set that the banks
 * pass on to the creditor's bank (Nordea's guide: characters SWIFT does not
 * allow are replaced): a-z A-Z 0-9 / - ? : ( ) . , ' + and space. Accents
 * and other letters are transliterated (å becomes a, ß becomes ss, æ becomes
 * ae), control characters are removed, and the rest becomes a space. Only
 * payments abroad use it; kronor payments to giro numbers are written as before.
 */
final class SwiftText
{
    public static function clean(string $value, int $length): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $value = str_replace(['&', '@', '_'], [' and ', ' at ', ' '], $value);
        $latin = Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value);
        $value = is_string($latin) ? $latin : (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = (string) preg_replace("/[^A-Za-z0-9\/\-?:().,'+ ]+/", ' ', $value);

        return rtrim(substr(trim((string) preg_replace('/\s+/', ' ', $value)), 0, $length));
    }
}
