<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Scripts in the signing PDFs made with dompdf (ADR-046 amendment 2026-11-18, S20).
 *
 * dompdf takes one font per text and does not fall back per glyph (checked in
 * the installed dompdf 3.1.6: a font-family list is resolved once per text
 * node, by whether the family exists, not by whether it has the glyph).
 * DejaVu Sans covers Latin, Greek and Cyrillic, so only Chinese, Japanese and
 * Korean need another font. Here such text is split into runs in the finished
 * HTML and each run gets its own font. The font is Noto Sans CJK (SIL OFL
 * 1.1), fetched by scripts/fetch-signing-cjk-font.sh and only where it is
 * needed. Without it a run is shown as [...] and the certificate carries a
 * notice: a name is never drawn with glyphs the font does not have.
 *
 * Only presentation changes. The text, the SHA-256 and the evidence digest
 * the certificate records are the same with or without the font.
 */
final class PdfScripts
{
    public const string FAMILY = 'notocjk';

    public const string PLACEHOLDER = '[…]';

    private const string CJK = '[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}\x{31F0}-\x{31FF}\x{3200}-\x{33FF}]+';

    /** Chinese, Japanese or Korean characters (Han, kana, Hangul, CJK punctuation and full-width forms). */
    public static function hasCjk(string $text): bool
    {
        return preg_match('/'.self::CJK.'/u', $text) === 1;
    }

    /** The Noto Sans CJK file, or null when this installation has not fetched it by hand. The application never downloads or runs anything itself. */
    public static function cjkFont(): ?string
    {
        $path = rtrim((string) config('nonox.signing.pdf_engine.fonts_dir'), '/').'/'.(string) config('nonox.signing.fonts.cjk_file');

        return self::usable($path) ? $path : null;
    }

    private static function usable(string $path): bool
    {
        clearstatcache(true, $path);

        return is_file($path) && ! is_link($path) && is_readable($path);
    }

    /**
     * Splits the text of finished HTML into script runs. With the CJK font
     * every run of Chinese, Japanese or Korean text is wrapped in a span of
     * its own font; without it each run becomes a placeholder and degraded is
     * true. Tags, attributes, style and title are never touched.
     *
     * @return array{html: string, cjk: bool, degraded: bool}
     */
    public static function prepare(string $html, bool $fontAvailable): array
    {
        if (! self::hasCjk($html)) {
            return ['html' => $html, 'cjk' => false, 'degraded' => false];
        }

        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return ['html' => $html, 'cjk' => false, 'degraded' => true];
        }

        $skip = false;
        $found = false;

        foreach ($parts as $index => $part) {
            if ($part[0] === '<') {
                if (preg_match('#^<(style|title|script)\b#i', $part) === 1) {
                    $skip = true;
                } elseif (preg_match('#^</(style|title|script)\b#i', $part) === 1) {
                    $skip = false;
                }

                continue;
            }

            if ($skip || ! self::hasCjk($part)) {
                continue;
            }

            $found = true;
            $parts[$index] = (string) preg_replace_callback(
                '/'.self::CJK.'/u',
                fn (array $run): string => $fontAvailable ? '<span class="cjk">'.$run[0].'</span>' : self::PLACEHOLDER,
                $part,
            );
        }

        $result = implode('', $parts);

        if ($found && $fontAvailable) {
            $css = '<style>.cjk { font-family: \''.self::FAMILY.'\', \'DejaVu Sans\', sans-serif; }</style>';
            $result = str_contains($result, '</head>') ? str_replace('</head>', $css.'</head>', $result) : $css.$result;
        }

        return ['html' => $result, 'cjk' => $found && $fontAvailable, 'degraded' => $found && ! $fontAvailable];
    }

    /** dompdf options and the font registered, for a render that uses the CJK font. */
    public static function dompdfWith(Options $options, string $fontPath): Dompdf
    {
        $directory = (string) config('nonox.signing.fonts.dompdf_dir');

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $options->setFontDir($directory);
        $options->setFontCache($directory);
        $options->setChroot([...(array) $options->getChroot(), $directory, dirname($fontPath)]);

        $pdf = new Dompdf($options);
        $pdf->getFontMetrics()->registerFont(['family' => self::FAMILY, 'style' => 'normal', 'weight' => 'normal'], $fontPath);

        return $pdf;
    }
}
