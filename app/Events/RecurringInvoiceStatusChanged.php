<?php

declare(strict_types=1);

namespace App\Events;

/**
 * An invoicing agreement was paused, resumed or ended, by a person or because
 * its end date passed (RunRecurringInvoice). Dispatched inside the
 * transaction, with ids only (ADR-080 §3.1). effectiveFrom is the
 * "Gäller från" date the person gave, or null.
 */
final readonly class RecurringInvoiceStatusChanged
{
    public function __construct(
        public int $companyId,
        public string $recurringInvoiceId,
        public ?int $userId,
        public string $status,
        public ?string $effectiveFrom = null,
    ) {}
}
