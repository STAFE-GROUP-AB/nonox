<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/** A PDF the engine wrote: where it is, its size and its SHA-256. */
final readonly class PdfFileResult
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public ?int $pages = null,
        public ?string $timestampUrl = null,
    ) {}
}
