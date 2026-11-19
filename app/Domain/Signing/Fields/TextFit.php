<?php

declare(strict_types=1);

namespace App\Domain\Signing\Fields;

/**
 * Whether a value fits in its field's box when it is stamped (ADR-046
 * amendment, gap 2, S6). The width is an estimate in PDF points from a
 * conservative width per character of Noto Sans (wide CJK and emoji
 * count as a full em), so a value that passes fits; the engine also
 * shrinks text to the box and clips it there. The font size is shrunk to
 * fit, down to 6 points; a value wider than the box at 6 points is refused.
 */
final class TextFit
{
    /** The smallest font size a value is stamped at. */
    public const int MIN_SIZE = 6;

    /** Space kept on each side of the text inside the box, in points. */
    public const float PADDING = 2.0;

    /** The estimated width of a text at a font size, in points. */
    public static function width(string $text, float $size): float
    {
        $ems = 0.0;

        foreach (mb_str_split($text) as $character) {
            $ems += match (true) {
                $character === ' ' => 0.3,
                preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{FF00}-\x{FFEF}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $character) === 1 => 1.0,
                preg_match('/\p{Mn}|\x{200D}|\x{FE0F}/u', $character) === 1 => 0.0,
                preg_match('/[MWmw@%]/', $character) === 1 => 0.95,
                preg_match('/\p{Lu}/u', $character) === 1 => 0.75,
                default => 0.62,
            };
        }

        return $ems * $size;
    }

    /** The width available for text in a box, in points. */
    public static function room(float $boxWidth): float
    {
        return max(0.0, $boxWidth - 2 * self::PADDING);
    }

    /** Whether the text fits in a box of this width at the smallest font size. */
    public static function fits(string $text, float $boxWidth): bool
    {
        return self::width($text, self::MIN_SIZE) <= self::room($boxWidth);
    }

    /** The font size for a text in a box: at most $largest, at most 60 % of the height, shrunk to fit, never below 6. */
    public static function size(string $text, float $boxWidth, float $boxHeight, int $largest): int
    {
        $size = (int) max(self::MIN_SIZE, min($largest, floor($boxHeight * 0.6)));

        while ($size > self::MIN_SIZE && self::width($text, $size) > self::room($boxWidth)) {
            $size--;
        }

        return $size;
    }

    /** The most characters of ordinary text that can fit in a box of this width at 6 points. */
    public static function maxLength(float $boxWidth): int
    {
        return max(1, (int) floor(self::room($boxWidth) / (0.62 * self::MIN_SIZE)));
    }
}
