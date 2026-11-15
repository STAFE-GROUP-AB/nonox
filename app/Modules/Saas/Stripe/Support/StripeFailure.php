<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Support;

use RuntimeException;

/**
 * A Stripe request that did not give an answer NONOX can use. The reason is
 * a fixed code, never Stripe's text (which may quote part of the key) and
 * never the key: `unauthorized` (401), `forbidden` (403, with the path that
 * needs a permission), `not_found` (404), `rate_limited` (429 after the
 * retries), `unavailable` (5xx or no answer) or `failed` (another 4xx).
 */
final class StripeFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $path = '')
    {
        parent::__construct("Stripe request failed: {$reason}");
    }

    public function retryable(): bool
    {
        return $this->reason === 'rate_limited' || $this->reason === 'unavailable';
    }
}
