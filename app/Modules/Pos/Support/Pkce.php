<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

/**
 * Proof Key for Code Exchange (RFC 7636), method S256 only.
 */
final class Pkce
{
    /** A fresh code verifier: 43 characters of the unreserved alphabet. */
    public static function verifier(): string
    {
        return self::base64Url(random_bytes(32));
    }

    public static function challenge(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
