<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Domain;

use RuntimeException;

/**
 * A received document is larger than NONOX takes (AcceptPeppolDocument::MAX_BYTES).
 * It stays at the access point; polling keeps its window open and the
 * operator is alerted, so it is not passed by.
 */
final class PeppolDocumentTooLarge extends RuntimeException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct("Peppol document {$reference} is too large.");
    }
}
