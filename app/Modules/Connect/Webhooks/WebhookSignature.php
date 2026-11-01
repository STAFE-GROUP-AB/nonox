<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

/**
 * The Nonox-Signature header (ADR-032): `t=<unix seconds>,v1=<hex>`, where
 * v1 is HMAC-SHA256 of "<t>.<raw body>" keyed with the webhook's secret.
 *
 * A receiver recomputes v1 over the raw body it received, compares in
 * constant time, rejects a timestamp more than five minutes away from its
 * own clock (replay), and remembers Nonox-Event-Id to ignore duplicates.
 * verify() is that check, used by our own tests and shown in the guide.
 */
final class WebhookSignature
{
    public const int TOLERANCE_SECONDS = 300;

    public static function header(string $secret, int $timestamp, string $body): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    public static function verify(string $secret, string $header, string $body, ?int $now = null, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
