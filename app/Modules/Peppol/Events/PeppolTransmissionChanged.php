<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Events;

use App\Modules\Peppol\Domain\TransmissionStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An invoice sent through Peppol reached the access point (SENT), was
 * delivered (DELIVERED) or failed (FAILED). Dispatched after commit, with
 * ids only (ADR-032, ADR-050).
 */
final readonly class PeppolTransmissionChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public string $transmissionId,
        public TransmissionStatus $status,
    ) {}
}
