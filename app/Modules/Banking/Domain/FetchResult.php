<?php

declare(strict_types=1);

namespace App\Modules\Banking\Domain;

/** What one fetch of a connection did. */
final readonly class FetchResult
{
    public function __construct(
        public int $new = 0,
        public int $skipped = 0,
        public int $payments = 0,
        public bool $consentEnded = false,
        public ?string $error = null,
        // The provider may answer later (down, timed out, rate limited): a queued fetch is tried again after a pause.
        public bool $retryable = false,
    ) {}
}
