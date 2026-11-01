<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer invoice became paid in full (RegisterPayment, also when bank
 * files are matched). Dispatched after commit, with ids only (ADR-032).
 */
final readonly class InvoicePaid implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public string $invoiceId,
        public int $paymentId,
    ) {}
}
