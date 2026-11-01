<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use RuntimeException;

/**
 * A webhook address that may not be used (WebhookAddress). The message is
 * Swedish, for people; the reason is a short stable code for the log.
 */
final class UnsafeWebhookAddress extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
