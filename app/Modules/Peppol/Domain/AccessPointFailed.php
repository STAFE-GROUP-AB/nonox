<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Domain;

use RuntimeException;

/**
 * The access point did not do what was asked. `retryable` when it may work
 * later (time-out, 429, 5xx); otherwise the document or the account is
 * wrong and trying again will not help. The message is for the log and the
 * transmission's last error: never personal data, never the API key.
 */
final class AccessPointFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false)
    {
        parent::__construct(mb_substr($message, 0, 500));
    }
}
