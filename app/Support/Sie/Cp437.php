<?php

declare(strict_types=1);

namespace App\Support\Sie;

/**
 * IBM PC 8-bit extended ASCII (codepage 437), the only character set SIE
 * allows (SIE 4C §5.8, #FORMAT PC8). The table is the codepage's upper half;
 * the lower half is plain ASCII.
 */
final class Cp437
{
    private const UPPER_HALF = [
        "\x80" => 'Ç', "\x81" => 'ü', "\x82" => 'é', "\x83" => 'â', "\x84" => 'ä', "\x85" => 'à', "\x86" => 'å', "\x87" => 'ç',
        "\x88" => 'ê', "\x89" => 'ë', "\x8A" => 'è', "\x8B" => 'ï', "\x8C" => 'î', "\x8D" => 'ì', "\x8E" => 'Ä', "\x8F" => 'Å',
        "\x90" => 'É', "\x91" => 'æ', "\x92" => 'Æ', "\x93" => 'ô', "\x94" => 'ö', "\x95" => 'ò', "\x96" => 'û', "\x97" => 'ù',
        "\x98" => 'ÿ', "\x99" => 'Ö', "\x9A" => 'Ü', "\x9B" => '¢', "\x9C" => '£', "\x9D" => '¥', "\x9E" => '₧', "\x9F" => 'ƒ',
        "\xA0" => 'á', "\xA1" => 'í', "\xA2" => 'ó', "\xA3" => 'ú', "\xA4" => 'ñ', "\xA5" => 'Ñ', "\xA6" => 'ª', "\xA7" => 'º',
        "\xA8" => '¿', "\xA9" => '⌐', "\xAA" => '¬', "\xAB" => '½', "\xAC" => '¼', "\xAD" => '¡', "\xAE" => '«', "\xAF" => '»',
        "\xB0" => '░', "\xB1" => '▒', "\xB2" => '▓', "\xB3" => '│', "\xB4" => '┤', "\xB5" => '╡', "\xB6" => '╢', "\xB7" => '╖',
        "\xB8" => '╕', "\xB9" => '╣', "\xBA" => '║', "\xBB" => '╗', "\xBC" => '╝', "\xBD" => '╜', "\xBE" => '╛', "\xBF" => '┐',
        "\xC0" => '└', "\xC1" => '┴', "\xC2" => '┬', "\xC3" => '├', "\xC4" => '─', "\xC5" => '┼', "\xC6" => '╞', "\xC7" => '╟',
        "\xC8" => '╚', "\xC9" => '╔', "\xCA" => '╩', "\xCB" => '╦', "\xCC" => '╠', "\xCD" => '═', "\xCE" => '╬', "\xCF" => '╧',
        "\xD0" => '╨', "\xD1" => '╤', "\xD2" => '╥', "\xD3" => '╙', "\xD4" => '╘', "\xD5" => '╒', "\xD6" => '╓', "\xD7" => '╫',
        "\xD8" => '╪', "\xD9" => '┘', "\xDA" => '┌', "\xDB" => '█', "\xDC" => '▄', "\xDD" => '▌', "\xDE" => '▐', "\xDF" => '▀',
        "\xE0" => 'α', "\xE1" => 'ß', "\xE2" => 'Γ', "\xE3" => 'π', "\xE4" => 'Σ', "\xE5" => 'σ', "\xE6" => 'µ', "\xE7" => 'τ',
        "\xE8" => 'Φ', "\xE9" => 'Θ', "\xEA" => 'Ω', "\xEB" => 'δ', "\xEC" => '∞', "\xED" => 'φ', "\xEE" => 'ε', "\xEF" => '∩',
        "\xF0" => '≡', "\xF1" => '±', "\xF2" => '≥', "\xF3" => '≤', "\xF4" => '⌠', "\xF5" => '⌡', "\xF6" => '÷', "\xF7" => '≈',
        "\xF8" => '°', "\xF9" => '∙', "\xFA" => '·', "\xFB" => '√', "\xFC" => 'ⁿ', "\xFD" => '²', "\xFE" => '■', "\xFF" => "\u{00A0}",
    ];

    /**
     * Characters that codepage 437 lacks but that have an obvious stand-in.
     * Anything else outside the codepage becomes "?".
     */
    private const STAND_INS = [
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
        "\u{2026}" => '...', "\u{20AC}" => 'EUR', "\u{00A0}" => ' ', "\u{202F}" => ' ',
    ];

    /** @var array<string, string>|null */
    private static ?array $reverse = null;

    public static function decode(string $bytes): string
    {
        return strtr($bytes, self::UPPER_HALF);
    }

    /**
     * UTF-8 to codepage 437. Control characters, which SIE forbids inside a
     * field (§5.7), become spaces.
     */
    public static function encode(string $utf8): string
    {
        $utf8 = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', mb_scrub($utf8, 'UTF-8'));

        if (! preg_match('/[^\x00-\x7F]/', $utf8)) {
            return $utf8;
        }

        self::$reverse ??= array_flip(self::UPPER_HALF);

        return (string) preg_replace_callback('/[^\x00-\x7F]/u', function (array $match): string {
            return self::STAND_INS[$match[0]] ?? self::$reverse[$match[0]] ?? '?';
        }, $utf8);
    }
}
