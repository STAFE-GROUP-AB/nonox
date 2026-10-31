<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/**
 * Kiosk PINs. The PIN itself is never stored: only a random per-person salt
 * and a PBKDF2-SHA256 verifier, which the kiosk recomputes with WebCrypto to
 * check a PIN without the network (ADR-022). Four digits are a small space,
 * so the verifier slows guessing down but cannot stop someone holding a
 * kiosk's storage from trying all 10 000; attempts at the kiosk are limited
 * and locked out instead.
 */
final class Pin
{
    public const int ITERATIONS = 100_000;

    public const int LENGTH = 4;

    public static function isValidFormat(string $pin): bool
    {
        return preg_match('/^\d{'.self::LENGTH.'}$/', $pin) === 1;
    }

    /** Too easy to guess: one digit repeated or a straight run (1234, 9876). */
    public static function isTrivial(string $pin): bool
    {
        if (count(array_unique(str_split($pin))) === 1) {
            return true;
        }

        return str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
    }

    public static function newSalt(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function verifier(string $pin, string $salt): string
    {
        return hash_pbkdf2('sha256', $pin, (string) hex2bin($salt), self::ITERATIONS, 64);
    }

    public static function matches(string $pin, ?string $salt, ?string $verifier): bool
    {
        if ($salt === null || $verifier === null || ! self::isValidFormat($pin)) {
            return false;
        }

        return hash_equals($verifier, self::verifier($pin, $salt));
    }
}
