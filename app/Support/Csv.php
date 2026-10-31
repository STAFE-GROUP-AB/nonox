<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Makes a value safe to write to a CSV file that may be opened in a
 * spreadsheet: a text starting with =, +, -, @, tab or carriage return
 * would otherwise run as a formula (CSV injection). Numbers are left as
 * they are, so amounts like -125,00 stay numbers.
 */
final class Csv
{
    public static function cell(mixed $value): string
    {
        $text = (string) $value;

        if ($text === '' || is_numeric(str_replace([',', ' ', "\u{a0}"], ['.', '', ''], $text))) {
            return $text;
        }

        return in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return list<string>
     */
    public static function row(array $row): array
    {
        return array_values(array_map(self::cell(...), $row));
    }
}
