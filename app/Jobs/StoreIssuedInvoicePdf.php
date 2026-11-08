<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Models\Invoice;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\RunsForCompany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Stores an invoice's PDF as it was issued (IssuedInvoicePdf), dispatched
 * by IssueInvoice after its transaction commits. Idempotent.
 */
class StoreIssuedInvoicePdf implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsForCompany;

    public int $tries = 3;

    public function __construct(public int $companyId, public string $invoiceId) {}

    public function handle(): void
    {
        CurrentCompany::run($this->companyId, function (): void {
            $invoice = Invoice::query()->find($this->invoiceId);

            if ($invoice instanceof Invoice) {
                IssuedInvoicePdf::store($invoice);
            }
        });
    }
}
