<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The invoice PDF as it was issued (ADR-075, decision O6). Rendered once,
 * right after IssueInvoice commits (StoreIssuedInvoicePdf), and kept: the
 * e-mail, a reminder's attachment, the download, the API, Peppol's
 * embedded copy, the archive package and the customer portal all hand out
 * these same bytes, so a later change of the company's address, bank
 * details or logo never changes an invoice that was sent.
 *
 * An invoice issued before stored PDFs existed (or whose job has not run
 * yet) is rendered from its data as before; the portal labels that copy as
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
                return InvoiceDocument::query()->create([
                    'invoice_id' => $invoice->id,
                    'path' => $path,
                    'sha256' => hash('sha256', $bytes),
                    'size' => strlen($bytes),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Stored by a parallel run first: that copy stands.
                Storage::disk(InvoiceDocument::DISK)->delete($path);

                return InvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();
            }
        });
    }

    /**
     * The PDF to hand out: the stored one when there is one (and its bytes
     * match their hash), else rendered now.
     *
     * @return array{bytes: string, stored: bool}
     */
    public static function get(Invoice $invoice): array
    {
        $document = $invoice->isDraft() ? null : CurrentCompany::run($invoice->company_id, fn (): ?InvoiceDocument => InvoiceDocument::query()->where('invoice_id', $invoice->id)->first());

        if ($document instanceof InvoiceDocument) {
            $bytes = Storage::disk(InvoiceDocument::DISK)->get($document->path);

            if (is_string($bytes) && hash_equals($document->sha256, hash('sha256', $bytes))) {
                return ['bytes' => $bytes, 'stored' => true];
            }
        }

        return ['bytes' => InvoicePdf::render($invoice), 'stored' => false];
    }

    public static function bytes(Invoice $invoice): string
    {
        return self::get($invoice)['bytes'];
    }
}
