<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The text layer of a PDF, one line per text line, so reports from cash
 * registers can be read with a saved template instead of NONOX AI
 * (ADR-023). Uses smalot/pdfparser (LGPL-3.0), plain PHP.
 *
 * Null when the file has no text, such as a scanned page or a photo saved
 * as PDF, or cannot be read. Large files and long documents are left
 * alone, since a daily report is a page or two: a crafted PDF must not be
 * able to hold the queue worker or fill its memory.
 */
final class PdfText
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public const int MAX_PAGES = 10;

    private const int DECODE_MEMORY_LIMIT = 32 * 1024 * 1024;

    public static function isPdf(string $contents): bool
    {
        return str_starts_with(ltrim(substr($contents, 0, 1024)), '%PDF-');
    }

    public static function extract(string $contents): ?string
    {
        if (! self::isPdf($contents) || strlen($contents) > self::MAX_BYTES) {
            return null;
        }

        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(self::DECODE_MEMORY_LIMIT);

        try {
            $document = (new Parser([], $config))->parseContent($contents);

            if (count($document->getPages()) > self::MAX_PAGES) {
                return null;
            }

            $text = $document->getText(self::MAX_PAGES);
        } catch (Throwable) {
            return null;
        }

        $lines = array_map(
            fn (string $line): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)),
            preg_split('/\r\n|\r|\n/', $text) ?: [],
        );
        $text = implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));

        return mb_strlen($text) < 10 ? null : $text;
    }
}
