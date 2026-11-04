<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Domain;

/**
 * A document the access point received for one of the installation's
 * companies: the UBL exactly as it came over the network, the provider's
 * id for it, and the participants from the network envelope when the
 * provider tells them.
 */
final readonly class InboundDocument
{
    public function __construct(
        public string $reference,
        public string $xml,
        public ?string $sender = null,
        public ?string $receiver = null,
    ) {}
}
