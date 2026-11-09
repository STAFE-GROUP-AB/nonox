<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invoicing;

use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\IssuedInvoicePdf;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\Toast;
use App\Support\WorkQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Invoice pages. Route model binding goes through the company scope, so
 * another company's invoice answers 404.
 */
class InvoiceController
{
    public function show(Invoice $invoice): View|RedirectResponse
    {
        Gate::authorize('view', $invoice);

        if ($invoice->isDraft()) {
            return redirect()->route('invoicing.invoices.edit', $invoice);
        }

        return view('invoicing.invoices.show', ['invoice' => $invoice]);
    }

    public function edit(Invoice $invoice): View|RedirectResponse
    {
        Gate::authorize('view', $invoice);

        if (! $invoice->isDraft()) {
            return redirect()->route('invoicing.invoices.show', $invoice);
        }

        return view('invoicing.invoices.edit', ['invoice' => $invoice]);
    }

    public function pdf(Invoice $invoice): Response|RedirectResponse
    {
        Gate::authorize('view', $invoice);

        if (! WorkQuota::attempt(Company::query()->findOrFail($invoice->company_id), 'pdf')) {
            Toast::flash(WorkQuota::message(), 'error');

            return back();
        }

        return response(IssuedInvoicePdf::bytes($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.InvoicePdf::filename($invoice).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0',
        ]);
    }
}
