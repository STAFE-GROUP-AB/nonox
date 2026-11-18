<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/** A sealed PDF: where it is, the level it reached and the seal certificate's fingerprint. */
final readonly class SealResult
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public SealLevel $level,
        public string $certificateSha256,
        public ?string $timestampUrl = null,
    ) {}
}
