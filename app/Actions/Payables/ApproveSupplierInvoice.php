<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Domain\Payables\SupplierInvoiceStatus;
use App\Events\SupplierInvoiceApproved;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Policies\SupplierInvoicePolicy;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Approves (attesterar) a registered supplier invoice, so it may be paid.
 * Whoever registered it may not approve it while the company requires
 * separate approval (AGENTS.md §4).
 */
class ApproveSupplierInvoice
{
    public function __invoke(User $user, SupplierInvoice $invoice): SupplierInvoice
    {
        if ((new SupplierInvoicePolicy)->blockedBySegregation($user, $invoice)) {
            throw ValidationException::withMessages(['approve' => __('Du registrerade fakturan och kan därför inte attestera den. Någon annan med rätt att attestera behöver göra det.')]);
        }

        Gate::forUser($user)->authorize('approve', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): SupplierInvoice => DB::transaction(function () use ($user, $invoice): SupplierInvoice {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status !== SupplierInvoiceStatus::Registered) {
                throw ValidationException::withMessages(['approve' => __('Fakturan väntar inte på attest.')]);
            }

            $invoice->forceFill([
                'status' => SupplierInvoiceStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $user->id,
            ])->save();

            Audit::record('supplier_invoice.approved', $invoice, ['number' => $invoice->number, 'external_ref' => $invoice->external_ref], $invoice->company_id, $user);

            event(new SupplierInvoiceApproved($invoice->company_id, $invoice->id));

            return $invoice;
        }));
    }
}
