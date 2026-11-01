<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An issued customer invoice was sent to the customer (SendInvoice).
 * Dispatched after commit, with ids only (ADR-032).
 */
final readonly class InvoiceSent implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public string $invoiceId,
    ) {}
}
