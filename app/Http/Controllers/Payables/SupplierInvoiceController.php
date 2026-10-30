<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Models\SupplierInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supplier invoice pages. Route model binding goes through the company
 * scope, so another company's invoice answers 404.
 */
class SupplierInvoiceController
{
    public function show(SupplierInvoice $supplierInvoice): View|RedirectResponse
    {
        Gate::authorize('view', $supplierInvoice);

        if ($supplierInvoice->isDraft()) {
            return redirect()->route('payables.invoices.edit', $supplierInvoice);
        }

        return view('payables.invoices.show', ['invoice' => $supplierInvoice]);
    }

    public function edit(SupplierInvoice $supplierInvoice): View|RedirectResponse
    {
        Gate::authorize('view', $supplierInvoice);

        if (! $supplierInvoice->isDraft()) {
            return redirect()->route('payables.invoices.show', $supplierInvoice);
        }

        return view('payables.invoices.edit', ['invoice' => $supplierInvoice]);
    }

    /**
     * The invoice file, shown inline. Its type was read from its content when
     * it was uploaded, and the browser is told not to guess another.
     */
    public function file(SupplierInvoice $supplierInvoice): StreamedResponse
    {
        Gate::authorize('view', $supplierInvoice);

        abort_if($supplierInvoice->file_path === null, 404);

        return Storage::disk(AttachToVoucher::DISK)->response($supplierInvoice->file_path, $supplierInvoice->file_name, [
            'Content-Type' => (string) $supplierInvoice->file_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ], 'inline');
    }
}
