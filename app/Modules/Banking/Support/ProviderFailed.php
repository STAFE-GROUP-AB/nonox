<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use RuntimeException;

/**
 * The provider could not be reached or answered unexpectedly. The message
 * is safe to store and log: it never contains tokens, session ids or
 * account data. `retryable` when it may work later (no answer, a time-out,
 * 429 or 5xx); otherwise the request or the configuration is wrong and
 * trying again will not help.
 */
final class ProviderFailed extends RuntimeException
{
    public function __construct(string $message = '', public readonly bool $retryable = false)
    {
        parent::__construct(mb_substr($message, 0, 500));
    }
}
