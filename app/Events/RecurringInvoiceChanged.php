<?php

declare(strict_types=1);

namespace App\Events;

/**
 * An invoicing agreement (avtalsfakturering) was created or changed
 * (SaveRecurringInvoice). Dispatched inside the saving transaction, with ids
 * only, so a listener can record the change atomically with it (ADR-080
 * §3.1). effectiveFrom is the "Gäller från" date the person gave, or null.
 */
final readonly class RecurringInvoiceChanged
{
    public function __construct(
        public int $companyId,
        public string $recurringInvoiceId,
        public ?int $userId,
        public bool $created,
        public ?string $effectiveFrom = null,
    ) {}
}
