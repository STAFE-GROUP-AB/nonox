<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

/**
 * How one delivery attempt went: the receiver's status code (null when no
 * answer came), a short stable reason when it failed, and how long it took.
 */
final readonly class WebhookAttempt
{
    public function __construct(
        public ?int $statusCode,
        public ?string $error,
        public int $durationMs,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null;
    }
}
