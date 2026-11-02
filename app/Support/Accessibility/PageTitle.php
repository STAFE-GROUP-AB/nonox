<?php

declare(strict_types=1);

namespace App\Support\Accessibility;

use Illuminate\Support\Str;

/**
 * The <title> of a signed-in page (WCAG 2.4.2): the page's own heading, then
 * the company, then NONOX. The heading is read from the rendered page, so
 * every page gets a title that says what it is without each view setting one.
 */
final class PageTitle
{
    public const string SUFFIX = 'NONOX';

    /** The text of the first level-one heading in the HTML, else the first level-two heading. */
    public static function heading(string ...$html): ?string
    {
        foreach (['h1', 'h2'] as $level) {
            foreach ($html as $part) {
                if (preg_match("~<{$level}\\b[^>]*>(.*?)</{$level}>~is", $part, $match) !== 1) {
                    continue;
                }

                $text = Str::squish(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    public static function compose(?string $page, ?string $company = null): string
    {
        $parts = array_values(array_filter([$page, $company], fn (?string $part): bool => $part !== null && trim($part) !== ''));
        $parts[] = self::SUFFIX;

        return implode(' – ', array_unique($parts));
    }
}
