<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Support;

use App\Support\Pdf\PdfText;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The text of a document for NONOX AI, page by page, so a proposal can say
 * which page it read a fact from. Only text is sent to the AI provider,
 * never the file, so personal data can be masked first
 * (PersonalDataMasker). A PDF without a text layer (a scan or a photo)
 * cannot be read; the person fills in the facts by hand.
 */
final class DocumentText
{
    public const array MIMES = ['application/pdf', 'text/plain'];

    /** @return ?list<string> the pages, or null when there is no readable text */
    public static function pages(string $contents, string $mime): ?array
    {
        if ($mime === 'text/plain') {
            $text = trim((string) mb_convert_encoding($contents, 'UTF-8', 'UTF-8, Windows-1252'));

            return $text === '' ? null : [$text];
        }

        if (! PdfText::isPdf($contents) || strlen($contents) > PdfText::MAX_BYTES) {
            return null;
        }

        $config = new Config;
        $config->setRetainImageContent(false);

        try {
            $pages = (new Parser([], $config))->parseContent($contents)->getPages();

            if (count($pages) > PdfText::MAX_PAGES) {
                return null;
            }

            $texts = array_map(fn ($page): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $page->getText())), array_values($pages));
        } catch (Throwable) {
            return null;
        }

        return mb_strlen(implode('', $texts)) < 10 ? null : $texts;
    }

    /**
     * The masked text with page markers, as the agents receive it.
     *
     * @param  list<string>  $pages
     * @return array{text: string, masked: int}
     */
    public static function forAi(array $pages): array
    {
        $masked = 0;
        $parts = [];

        foreach ($pages as $index => $page) {
            $result = PersonalDataMasker::mask($page);
            $masked += $result['masked'];
            $parts[] = '[Sida '.($index + 1)."]\n".$result['text'];
        }

        return ['text' => implode("\n\n", $parts), 'masked' => $masked];
    }
}
