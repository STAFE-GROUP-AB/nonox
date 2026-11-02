<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

use RuntimeException;

/**
 * The till's API refused or did not answer. The message holds the status
 * and the provider's error code only, never tokens or the whole answer.
 */
final class PosApiFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly bool $credentialsRejected = false)
    {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        return $this->status === null || $this->status === 408 || $this->status === 425 || $this->status === 429 || $this->status >= 500;
    }
}
