<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

/**
 * The finished file of a provider run (ADR-046 S17), handed to SignAtLink so
 * that it becomes the signer's revision in the same transaction that signs:
 * the run's id, and the PDF with the signer's own PAdES signature (a local
 * file, checked by the engine), with its size and SHA-256.
 */
final readonly class ProviderSigned
{
    public function __construct(
        public int $sessionId,
        public string $path,
        public string $sha256,
        public int $bytes,
    ) {}
}
