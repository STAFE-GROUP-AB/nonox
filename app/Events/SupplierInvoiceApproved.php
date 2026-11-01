<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A supplier invoice was approved (attesterad, ApproveSupplierInvoice).
 * Dispatched after commit, with ids only (ADR-032).
 */
final readonly class SupplierInvoiceApproved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $companyId,
        public string $supplierInvoiceId,
    ) {}
}
