<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The invoice PDF as it was issued (ADR-075, decision O6). Rendered once
 * and kept, by StoreIssuedInvoicePdf right after IssueInvoice commits or,
 * when something needs the PDF before that job has run (an e-mail sent
 * straight after issuing, a recurring invoice), by get() itself: the
 * e-mail, a reminder's attachment, the download, the API, Peppol's
 * embedded copy, the archive package and the customer portal all hand out
 * these same bytes, so a later change of the company's address, bank
 * details or logo never changes an invoice that was sent.
 *
 * An invoice issued before stored PDFs existed (stores_issued_pdf false) is
 * rendered from its data as before; the portal labels that copy as
 * re-rendered. A draft is always rendered.
 *
 * TODO(legal-verify): L6 — whether the law requires keeping the exact issued
 * PDF (ML 17 kap. 30–31 §§, BFL 5 kap. 6 §); this stores it either way.
 */
final class IssuedInvoicePdf
{
    /** Stores the issued PDF once; does nothing for a draft or one already stored. Inside the invoice's company. */
    public static function store(Invoice $invoice): ?InvoiceDocument
    {
        if ($invoice->isDraft()) {
            return null;
        }

        return CurrentCompany::run($invoice->company_id, function () use ($invoice): InvoiceDocument {
            $existing = InvoiceDocument::query()->where('invoice_id', $invoice->id)->first();

            if ($existing instanceof InvoiceDocument) {
                return $existing;
            }

            $bytes = InvoicePdf::render($invoice);
            $path = "invoices/{$invoice->company_id}/".Str::uuid()->toString().'.pdf';
            Storage::disk(InvoiceDocument::DISK)->put($path, $bytes);

            try {
                // In a savepoint of its own: a parallel store wins on the unique key without spoiling the caller's transaction.
                return DB::transaction(fn (): InvoiceDocument => InvoiceDocument::query()->create([
                    'invoice_id' => $invoice->id,
                    'path' => $path,
                    'sha256' => hash('sha256', $bytes),
                    'size' => strlen($bytes),
                ]));
            } catch (UniqueConstraintViolationException) {
                // Stored by a parallel run first: that copy stands.
                Storage::disk(InvoiceDocument::DISK)->delete($path);

                return InvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();
            }
        });
    }

    /**
     * The PDF to hand out: the stored one (when its bytes match their hash).
     * An invoice issued with stored PDFs whose PDF is not stored yet is
     * stored now, so the first copy sent is the one kept. Anything else is
     * rendered now.
     *
     * @return array{bytes: string, stored: bool}
     */
    public static function get(Invoice $invoice): array
    {
        $document = $invoice->isDraft() ? null : CurrentCompany::run($invoice->company_id, fn (): ?InvoiceDocument => InvoiceDocument::query()->where('invoice_id', $invoice->id)->first());

        if ($document === null && ! $invoice->isDraft() && $invoice->stores_issued_pdf) {
            $document = self::store($invoice);
        }

        if ($document instanceof InvoiceDocument) {
            $bytes = Storage::disk(InvoiceDocument::DISK)->get($document->path);

            if (is_string($bytes) && hash_equals($document->sha256, hash('sha256', $bytes))) {
                return ['bytes' => $bytes, 'stored' => true];
            }
        }

        return ['bytes' => InvoicePdf::render($invoice), 'stored' => false];
    }

    /**
     * Whether what get() hands out is the PDF as issued, without rendering
     * or storing anything: a stored PDF whose bytes match their hash (checked
     * once and remembered, the row never changes), or an invoice issued with
     * stored PDFs whose PDF get() will store on first use.
     */
    public static function isStored(Invoice $invoice): bool
    {
        if ($invoice->isDraft()) {
            return false;
        }

        $document = CurrentCompany::run($invoice->company_id, fn (): ?InvoiceDocument => InvoiceDocument::query()->where('invoice_id', $invoice->id)->first());

        if (! $document instanceof InvoiceDocument) {
            return $invoice->stores_issued_pdf;
        }

        return (bool) Cache::rememberForever("issued-invoice-pdf-intact:{$document->id}:{$document->sha256}", function () use ($document): bool {
            $bytes = Storage::disk(InvoiceDocument::DISK)->get($document->path);

            return is_string($bytes) && hash_equals($document->sha256, hash('sha256', $bytes));
        });
    }

    public static function bytes(Invoice $invoice): string
    {
        return self::get($invoice)['bytes'];
    }
}
