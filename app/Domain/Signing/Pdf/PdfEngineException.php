<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use RuntimeException;

/**
 * An operation of the PDF engine that could not be done. The message is a
 * plain Swedish sentence; it never holds the file's content, a path inside
 * the work directory or a secret, so it may be shown and logged.
 */
final class PdfEngineException extends RuntimeException
{
    public function __construct(
        public readonly PdfEngineFailure $failure,
        public readonly ?PdfRefusal $refusal = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($refusal?->message() ?? $failure->message());
    }

    public static function notInstalled(): self
    {
        return new self(PdfEngineFailure::NotInstalled);
    }

    public static function refused(PdfRefusal $refusal): self
    {
        return new self(PdfEngineFailure::Refused, $refusal);
    }
}
