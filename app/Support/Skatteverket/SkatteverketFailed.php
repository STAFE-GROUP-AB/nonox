<?php

declare(strict_types=1);

namespace App\Support\Skatteverket;

use RuntimeException;

/**
 * Skatteverket refused or failed a call. The user message is Skatteverket's
 * own text for the end user when it gave one (meddelandeTillAnvandare);
 * the general terms require that the meaning of an error is shown
 * (Allmänna villkor API Momsdeklaration 5 b). Never carries a token.
 */
final class SkatteverketFailed extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $userMessage,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct("Skatteverket answered {$status}".($errorCode === null ? '' : " ({$errorCode})"));
    }

    /** The session with Skatteverket has ended; the user must sign in again. */
    public function needsSignIn(): bool
    {
        return $this->status === 401;
    }
}
