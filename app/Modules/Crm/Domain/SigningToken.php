<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * The secret in a signing link (ADR-046): 32 random bytes (256 bits) from
 * the operating system's CSPRNG, base64url without padding (43
 * characters). Only its SHA-256 is stored, so a copy of the database gives
 * no working links. With 256 bits of entropy a fast hash is enough and
 * allows an indexed lookup; the stored hash is compared again with
 * hash_equals after the lookup.
 */
final class SigningToken
{
    public const string PATTERN = '[A-Za-z0-9_-]{43}';

    /** @return array{token: string, hash: string} */
    public static function generate(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function isWellFormed(string $token): bool
    {
        return preg_match('/^'.self::PATTERN.'$/', $token) === 1;
    }
}
