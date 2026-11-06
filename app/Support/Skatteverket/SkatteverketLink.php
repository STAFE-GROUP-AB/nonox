<?php

declare(strict_types=1);

namespace App\Support\Skatteverket;

/**
 * A link from one of Skatteverket's APIs that NONOX shows to the user: the
 * VAT signing link, the employer declaration's review link and its error
 * report (ADR-054). Only a link to Skatteverket's own site is shown.
 */
final class SkatteverketLink
{
    /**
     * Browsers read a backslash as a slash and drop what is before an "@",
     * so a link with either, or with whitespace, is refused before the host
     * is read.
     */
    public static function isSkatteverket(string $link): bool
    {
        if (! str_starts_with($link, 'https://') || preg_match('/[\\\\@\s\x00-\x1f\x7f]/', $link) === 1) {
            return false;
        }

        $host = parse_url($link, PHP_URL_HOST);

        return is_string($host) && preg_match('/^[a-z0-9.-]+$/', $host) === 1 && ($host === 'skatteverket.se' || str_ends_with($host, '.skatteverket.se'));
    }

    /** The link when it goes to Skatteverket, else null. */
    public static function orNull(mixed $link): ?string
    {
        return is_string($link) && self::isSkatteverket($link) ? $link : null;
    }
}
