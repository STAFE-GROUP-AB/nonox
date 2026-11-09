<?php

declare(strict_types=1);

namespace App\Modules\Crm\Events;

/**
 * An agreement in the agreement register was created, changed or terminated
 * (SaveAgreement). Dispatched inside the transaction, with ids only, so a
 * listener can record it atomically (ADR-080 §3.1).
 */
final readonly class CrmAgreementChanged
{
    public function __construct(
        public int $companyId,
        public int $agreementId,
        public ?int $userId,
        public bool $created,
    ) {}
}
