<?php

declare(strict_types=1);

namespace App\Domain\Signing;

/**
 * The instance's signing host (ADR-046 amendment, decision 18, S20): one host
 * name for every company's signing links, such as sign.example.org, pointed at
 * this same application by the operator. There is no custom domain per
 * company, since NONOX has no TLS automation. A value that is not a plain
 * host name is ignored, so a mistake in .env can never send a link to a
 * strange address.
 */
final class SigningHost
{
    /** The configured host in lower case, or null when none is set or it is not a plain host name. */
    public static function host(): ?string
    {
        $host = strtolower(trim((string) config('nonox.signing.host')));

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        return preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1 ? $host : null;
    }

    public static function scheme(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        return $scheme === 'http' ? 'http' : 'https';
    }

    /** The root address of the signing host, or null. */
    public static function root(): ?string
    {
        $host = self::host();

        return $host === null ? null : self::scheme().'://'.$host;
    }

    /** The link a signer opens, on the signing host when there is one. */
    public static function link(string $token): string
    {
        $path = route('signing.show', ['token' => $token], false);
        $root = self::root();

        return $root === null ? url($path) : $root.$path;
    }

    /** Paths a request to the signing host may reach: the signing pages and what they load. */
    public static function allows(string $path): bool
    {
        return $path === 'up' || $path === 'favicon.ico' || $path === 'robots.txt' || preg_match('#^(signera|build|brand)(/|$)#', $path) === 1;
    }
}
