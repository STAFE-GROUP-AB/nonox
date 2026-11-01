<?php

declare(strict_types=1);

namespace App\Support\Search;

/**
 * The same ranking as DatabaseSearchProvider, for lists held in memory
 * (pages, actions, the user's companies): exact 800, starts with 600, a
 * word starts with 500, contains 400, keyword 300, a word within one or two
 * typos 150–250. Null when nothing matches.
 */
final class TextScore
{
    /** @param  list<string>  $keywords  other words the item answers to */
    public static function score(string $label, string $text, array $keywords = []): ?float
    {
        $needle = self::normalise($text);
        $haystack = self::normalise($label);

        if ($needle === '' || $haystack === '') {
            return null;
        }

        if ($haystack === $needle) {
            return 800.0;
        }

        if (str_starts_with($haystack, $needle)) {
            return 600.0 + self::closeness($haystack, $needle);
        }

        if (str_contains(' '.$haystack, ' '.$needle)) {
            return 500.0 + self::closeness($haystack, $needle);
        }

        if (str_contains($haystack, $needle)) {
            return 400.0;
        }

        // Every word typed starts a word of the label: "ny lev" → "Ny leverantörsfaktura".
        $words = explode(' ', $haystack);
        $typed = explode(' ', $needle);

        if (count($typed) > 1 && self::allPrefixWords($typed, $words)) {
            return 450.0;
        }

        foreach ($keywords as $keyword) {
            $keyword = self::normalise($keyword);

            if ($keyword !== '' && (str_starts_with($keyword, $needle) || str_contains(' '.$keyword, ' '.$needle))) {
                return 300.0;
            }
        }

        if (mb_strlen($needle) < 4) {
            return null;
        }

        $best = null;

        foreach ([...$words, ...array_map(self::normalise(...), $keywords)] as $word) {
            $distance = levenshtein($needle, mb_substr($word, 0, mb_strlen($needle) + 1));
            $allowed = mb_strlen($needle) >= 7 ? 2 : 1;

            if ($distance <= $allowed) {
                $best = max($best ?? 0.0, 250.0 - 50.0 * $distance);
            }
        }

        return $best;
    }

    public static function normalise(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }

    /** Shorter labels rank above longer ones with the same start. */
    private static function closeness(string $haystack, string $needle): float
    {
        return 50.0 * mb_strlen($needle) / max(mb_strlen($haystack), 1);
    }

    /**
     * @param  list<string>  $typed
     * @param  list<string>  $words
     */
    private static function allPrefixWords(array $typed, array $words): bool
    {
        foreach ($typed as $part) {
            $found = false;

            foreach ($words as $word) {
                if (str_starts_with($word, $part)) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }
}
