<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Domain;

use App\Support\Peppol\PeppolIdentifier;

/**
 * What an access point answers. Plain value objects, so a driver for
 * another provider only has to fill them in.
 */
final readonly class ParticipantLookup
{
    public function __construct(
        public PeppolIdentifier $participant,
        public bool $registered,
        public bool $acceptsInvoice,
        public bool $acceptsCreditNote,
    ) {}

    public function acceptsBilling(): bool
    {
        return $this->registered && $this->acceptsInvoice;
    }
}
