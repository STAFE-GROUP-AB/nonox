<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Support;

/**
 * Masks personal numbers, bank details, addresses, e-mail addresses and
 * phone numbers in a document's text before it is sent to the AI provider
 * (ADR-033 §6, data minimisation). The agents need none of them: age comes
 * from the employee register, never from the AI. Deterministic and
 * conservative: whatever looks like one of these is replaced by a marker
 * such as [personnummer].
 */
final class PersonalDataMasker
{
    /** Lines with these words have their numbers, or the rest of the line, masked. */
    private const string BANK_WORDS = '(?:bankkonto|kontonummer|kontonr|clearing|clearingnummer|bankgiro|plusgiro|iban|bic|swift|lönekonto)';

    private const string ADDRESS_WORDS = '(?:adress|gatuadress|postadress|bostadsadress|hemadress|bostad|c\/o)';

    /** @return array{text: string, masked: int} */
    public static function mask(string $text): array
    {
        $count = 0;
        $replace = function (string $pattern, string $marker, string $subject) use (&$count): string {
            return (string) preg_replace_callback($pattern, function () use (&$count, $marker): string {
                $count++;

                return $marker;
            }, $subject);
        };

        // Bank details: everything after the word on the same line.
        $text = (string) preg_replace_callback('/(\b'.self::BANK_WORDS.'\b[^\n:]*[:.]?)[^\n]*/iu', function (array $match) use (&$count): string {
            $count++;

            return rtrim($match[1]).' [bankuppgift]';
        }, $text);

        // Addresses on a labelled line.
        $text = (string) preg_replace_callback('/(\b'.self::ADDRESS_WORDS.'\b[^\n:]*:)[^\n]*/iu', function (array $match) use (&$count): string {
            $count++;

            return $match[1].' [adress]';
        }, $text);

        $text = $replace('/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}(?:\s?[A-Z0-9]{1,4})?\b/u', '[bankuppgift]', $text);
        $text = $replace('/\b(?:19|20)?\d{6}\s?[-+]\s?\d{4}\b|\b(?:19|20)\d{10}\b/u', '[personnummer]', $text);
        $text = $replace('/\b\d{4}[- ]\d{6,12}\b/u', '[bankuppgift]', $text);
        $text = $replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[e-post]', $text);
        $text = $replace('/(?:\+46\s?|\b0)7\d[\s-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}\b|(?:\+46\s?|\b0)\d{1,3}[\s-]\d{5,8}\b/u', '[telefon]', $text);
        $text = $replace('/\b[\p{Lu}][\p{Ll}]+(?:gatan|vägen|gränd|torget|allén|stigen|backen|leden|plan|väg|gata)\s+\d+\s?[A-Za-z]?\b/u', '[gatuadress]', $text);
        $text = $replace('/\b\d{3}\s?\d{2}\s+[\p{Lu}][\p{Ll}]+(?:\s[\p{Lu}][\p{Ll}]+)?/u', '[postnummer och ort]', $text);

        return ['text' => $text, 'masked' => $count];
    }

    /** Text with whitespace collapsed and lower-cased, to find a quote in it. */
    public static function normalise(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
