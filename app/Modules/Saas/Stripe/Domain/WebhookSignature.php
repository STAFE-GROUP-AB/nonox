<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Domain;

/**
 * Stripe's webhook signature (ADR-080 §7.4, SA-R46), checked by hand as
 * docs.stripe.com/webhooks "Verify signatures manually" describes, read
 * 2026-11-15:
 *
 * 1. split the Stripe-Signature header on "," and each element on "=";
 *    keep `t` and every `v1`, discard everything else ("ignore signature
 *    schemes other than v1" against downgrade attacks; the debug entries
 *    are not signed);
 * 2. signed_payload = t + "." + the raw request body, unmodified;
 * 3. HMAC-SHA256 of it with the endpoint's signing secret;
 * 4. compare to every v1 in constant time; several are present while a
 *    secret is rolled, and one match is enough; then reject a timestamp
 *    outside the tolerance (the libraries' default: 5 minutes).
 */
final class WebhookSignature
{
    public const int TOLERANCE_SECONDS = 300;

    /**
     * @param  list<string>  $secrets  the active signing secrets (whsec_…)
     */
    public static function verify(string $header, string $body, array $secrets, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $element) {
            $pair = explode('=', trim($element), 2);

            if (count($pair) !== 2) {
                continue;
            }

            [$prefix, $value] = $pair;

            if ($prefix === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            }

            if ($prefix === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || $tolerance <= 0) {
            return false;
        }

        $matched = false;

        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

            foreach ($signatures as $signature) {
                // Every comparison is made, so the time does not tell which one matched.
                $matched = hash_equals($expected, $signature) || $matched;
            }
        }

        return $matched && abs($now - $timestamp) <= $tolerance;
    }

    /** A header as Stripe sends it, for tests and the demo. */
    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }
}
