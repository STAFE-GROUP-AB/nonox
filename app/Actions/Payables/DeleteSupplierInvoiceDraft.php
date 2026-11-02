<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Files\StoredFiles;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a draft supplier invoice and its file. A registered invoice can
 * never be removed (the policy, the model and a database trigger refuse).
 */
class DeleteSupplierInvoiceDraft
{
    public function __invoke(User $user, SupplierInvoice $invoice): void
    {
        Gate::forUser($user)->authorize('delete', $invoice);

        CurrentCompany::run($invoice->company_id, function () use ($user, $invoice): void {
            DB::transaction(function () use ($user, $invoice): void {
                Audit::record('supplier_invoice.draft_deleted', $invoice, [
                    'supplier' => $invoice->supplierName(),
                    'total_ore' => $invoice->total_ore->ore,
                ], $invoice->company_id, $user);

                $invoice->delete();

                StoredFiles::deleteUnreferenced([$invoice->file_path]);
            });
        });
    }
}
