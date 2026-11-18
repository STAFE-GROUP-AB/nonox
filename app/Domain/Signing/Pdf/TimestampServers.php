<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use InvalidArgumentException;

/**
 * The RFC 3161 timestamp servers the engine may contact (ADR-046 amendment,
 * gap 5 "Timestamp server"): only those in the instance configuration
 * nonox.signing.timestamp.urls (NONOX_SIGNING_TIMESTAMP_URLS), never one a
 * company or a request names, so no one can point the engine at an internal
 * address. The helper does not follow redirects either.
 */
final class TimestampServers
{
    /** @return list<string> */
    public static function configured(): array
    {
        $urls = config('nonox.signing.timestamp.urls', []);

        return array_values(array_filter(is_array($urls) ? $urls : [], fn (mixed $url): bool => is_string($url) && SealRequest::isTimestampUrl($url)));
    }

    /**
     * @param  list<string>  $urls
     *
     * @throws InvalidArgumentException when a URL is not in the instance configuration
     */
    public static function assertAllowed(array $urls): void
    {
        $allowed = self::configured();

        foreach ($urls as $url) {
            if (! in_array($url, $allowed, true)) {
                throw new InvalidArgumentException('A timestamp URL is not one of the configured timestamp servers (NONOX_SIGNING_TIMESTAMP_URLS).');
            }
        }
    }
}
