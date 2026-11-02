<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/**
 * Measures and fits text in DejaVu Sans, the font the label PDF uses
 * (ADR-002), with the glyph widths from dompdf's own font metrics, so the
 * layout computed here is the layout dompdf draws. Sizes are in points;
 * boxes in millimetres.
 */
final class TextFitter
{
    public const float PT_TO_MM = 25.4 / 72;

    /** The line height used in the label CSS. */
    public const float LINE_HEIGHT = 1.2;

    public const float MIN_PT = 5.0;

    /** @var array<string, array<int, int>> */
    private static array $widths = [];

    /** Width of the text in mm at a size. */
    public static function width(string $text, float $pt, bool $bold = false): float
    {
        $widths = self::widths($bold);
        $units = 0;

        foreach (mb_str_split($text) as $char) {
            $units += $widths[mb_ord($char)] ?? 600;
        }

        return $units / 1000 * $pt * self::PT_TO_MM;
    }

    /** Height in mm of a number of lines at a size. */
    public static function height(float $pt, int $lines = 1): float
    {
        return $pt * self::LINE_HEIGHT * self::PT_TO_MM * $lines;
    }

    /**
     * Breaks the text into lines no wider than the box, by words (a word
     * longer than the line is cut by character).
     *
     * @return list<string>
     */
    public static function wrap(string $text, float $pt, float $widthMm, bool $bold = false): array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $candidate = $line === '' ? $word : "{$line} {$word}";

            if (self::width($candidate, $pt, $bold) <= $widthMm) {
                $line = $candidate;

                continue;
            }

            if ($line !== '') {
                $lines[] = $line;
            }

            $line = '';

            foreach (mb_str_split($word) as $char) {
                if ($line !== '' && self::width($line.$char, $pt, $bold) > $widthMm) {
                    $lines[] = $line;
                    $line = '';
                }

                $line .= $char;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * The text in at most $maxLines lines; the last one ends with "…" when
     * the text is longer.
     *
     * @return array{lines: list<string>, truncated: bool}
     */
    public static function clamp(string $text, float $pt, float $widthMm, int $maxLines, bool $bold = false): array
    {
        $lines = self::wrap($text, $pt, $widthMm, $bold);

        if (count($lines) <= $maxLines) {
            return ['lines' => $lines, 'truncated' => false];
        }

        $kept = array_slice($lines, 0, max(1, $maxLines));
        $last = array_pop($kept) ?? '';

        while ($last !== '' && self::width($last.'…', $pt, $bold) > $widthMm) {
            $last = mb_substr($last, 0, -1);
        }

        $kept[] = rtrim($last).'…';

        return ['lines' => $kept, 'truncated' => true];
    }

    /**
     * The largest size (in half points, between the minimum and $maxPt) at
     * which the text fits the box in at most $maxLines lines; the minimum
     * when nothing fits.
     */
    public static function fit(string $text, float $widthMm, float $heightMm, int $maxLines, float $maxPt, bool $bold = false): float
    {
        for ($pt = $maxPt; $pt > self::MIN_PT; $pt -= 0.5) {
            $lines = self::wrap($text, $pt, $widthMm, $bold);

            if (count($lines) <= $maxLines && self::height($pt, max(1, count($lines))) <= $heightMm) {
                return $pt;
            }
        }

        return self::MIN_PT;
    }

    /** @return array<int, int> code point => advance in 1/1000 em */
    private static function widths(bool $bold): array
    {
        $file = $bold ? 'DejaVuSans-Bold' : 'DejaVuSans';

        if (isset(self::$widths[$file])) {
            return self::$widths[$file];
        }

        $widths = [];
        $path = base_path("vendor/dompdf/dompdf/lib/fonts/{$file}.ufm");

        foreach (is_file($path) ? (file($path) ?: []) : [] as $line) {
            if (preg_match('/^U (\d+) ; WX (\d+) ;/', $line, $match) === 1) {
                $widths[(int) $match[1]] = (int) $match[2];
            }
        }

        return self::$widths[$file] = $widths;
    }
}
