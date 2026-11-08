<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SigningRequest;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Modules\Connect\Models\ConnectSftpUpload;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Events\StockChanged;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Peppol\Models\PeppolTransmission;
use App\Modules\Planning\Events\PeriodAttested;
use App\Modules\Planning\Models\Publication;

/**
 * The events a webhook can subscribe to (ADR-032) and what each one says.
 *
 * Events are thin: ids, references, dates and amounts, never personal data
 * or free text. A receiver that needs more fetches the record through the
 * API with its own credential and scopes. Each event needs one of its
 * permissions, held by the person who created the webhook, both when it is
 * created and every time it is sent.
 */
final class WebhookEvents
{
    /** @var array<string, list<string>> event => permissions, any of which allows it */
    public const array EVENTS = [
        'voucher.posted' => ['vouchers:view'],
        'invoice.sent' => ['invoices:view'],
        'invoice.paid' => ['invoices:view'],
        'supplier_invoice.approved' => ['supplier-invoices:create', 'supplier-invoices:approve'],
        'planning.published' => ['planning:view'],
        'planning.attested' => ['planning:attest'],
        'sie.uploaded' => ['import:sie'],
        'stock.changed' => ['inventory:view'],
        'quote.signed' => ['invoices:view'],
        'invoice.peppol_sent' => ['invoices:view'],
        'invoice.peppol_failed' => ['invoices:view'],
    ];

    /** @return array<string, string> event => Swedish description */
    public static function labels(): array
    {
        return [
            'voucher.posted' => __('En verifikation har bokförts'),
            'invoice.sent' => __('En kundfaktura har skickats till kunden'),
            'invoice.paid' => __('En kundfaktura har blivit helt betald'),
            'supplier_invoice.approved' => __('En leverantörsfaktura har attesterats'),
            'planning.published' => __('En period av schemat har publicerats (Planering)'),
            'planning.attested' => __('Tid i schemat har attesterats (Planering)'),
            'sie.uploaded' => __('En SIE-fil har kommit in via SFTP och granskats'),
            'stock.changed' => __('Lagersaldot har ändrats: inleverans, plock, flytt, inventering, kassa, retur eller kassation (Lager)'),
            'quote.signed' => __('En offert har signerats via en signeringslänk (CRM)'),
            'invoice.peppol_sent' => __('En kundfaktura har lämnats till Peppol-nätverket som e-faktura (Peppol)'),
            'invoice.peppol_failed' => __('En e-faktura via Peppol kunde inte levereras (Peppol)'),
        ];
    }

    /** @return list<string> */
    public static function permissions(string $event): array
    {
        return self::EVENTS[$event] ?? [];
    }

    /**
     * The known events among the given ones, in the canonical order.
     *
     * @param  array<array-key, mixed>  $events
     * @return list<string>
     */
    public static function filter(array $events): array
    {
        return array_values(array_intersect(array_keys(self::EVENTS), array_map('strval', $events)));
    }

    /**
     * Must run inside the company.
     *
     * @return ?array<string, mixed>
     */
    public static function voucherPosted(string $voucherId): ?array
    {
        $voucher = Voucher::query()->with(['series', 'rows'])->find($voucherId);

        if (! $voucher instanceof Voucher) {
            return null;
        }

        return [
            'voucher_id' => $voucher->id,
            'series' => $voucher->series?->code,
            'number' => $voucher->number,
            'reference' => $voucher->reference(),
            'voucher_date' => $voucher->voucher_date->toDateString(),
            'fiscal_year_id' => $voucher->fiscal_year_id,
            'source' => $voucher->source->value,
            'amount_ore' => $voucher->rows->sum(fn (VoucherRow $row): int => $row->debit_ore->ore),
            'corrects_voucher_id' => $voucher->corrects_voucher_id,
        ];
    }

    /** @return ?array<string, mixed> */
    public static function invoiceSent(string $invoiceId): ?array
    {
        $invoice = Invoice::query()->find($invoiceId);

        if (! $invoice instanceof Invoice) {
            return null;
        }

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'kind' => $invoice->kind,
            'customer_id' => $invoice->customer_id,
            'total_ore' => $invoice->total_ore->ore,
            'currency' => 'SEK',
            'invoice_currency' => $invoice->invoice_currency,
            'foreign_total_minor' => $invoice->foreign_total_minor,
            'exchange_rate_micros' => $invoice->exchange_rate_micros,
            'invoice_date' => $invoice->invoice_date->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'ocr' => $invoice->ocr,
        ];
    }

    /**
     * Must run inside the company. The transmission's ids and status;
     * the receiver's Peppol ID is left out (it can be a sole trader's
     * personal identity number).
     *
     * @return ?array<string, mixed>
     */
    public static function peppolTransmission(string $transmissionId): ?array
    {
        $transmission = PeppolTransmission::query()->with('invoice:id,number,kind,customer_id')->find($transmissionId);

        if (! $transmission instanceof PeppolTransmission || $transmission->invoice === null) {
            return null;
        }

        return [
            'transmission_id' => $transmission->id,
            'invoice_id' => $transmission->invoice_id,
            'invoice_number' => $transmission->invoice->number,
            'kind' => $transmission->invoice->kind,
            'customer_id' => $transmission->invoice->customer_id,
            'document_type' => $transmission->document_type,
            'status' => $transmission->status->value,
            'reference' => $transmission->provider_reference,
            'sha256' => $transmission->xml_sha256,
            'sent_at' => $transmission->sent_at?->toAtomString(),
            'failed_at' => $transmission->failed_at?->toAtomString(),
        ];
    }

    /** @return ?array<string, mixed> */
    public static function invoicePaid(string $invoiceId, int $paymentId): ?array
    {
        $invoice = Invoice::query()->find($invoiceId);
        $payment = InvoicePayment::query()->find($paymentId);

        if (! $invoice instanceof Invoice || ! $payment instanceof InvoicePayment) {
            return null;
        }

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'customer_id' => $invoice->customer_id,
            'total_ore' => $invoice->total_ore->ore,
            'paid_ore' => $invoice->paid_ore->ore,
            'currency' => 'SEK',
            'invoice_currency' => $invoice->invoice_currency,
            'foreign_total_minor' => $invoice->foreign_total_minor,
            'foreign_paid_minor' => $invoice->isForeign() ? $invoice->foreign_paid_minor : null,
            'payment_id' => $payment->id,
            'amount_ore' => $payment->amount_ore->ore,
            'foreign_amount_minor' => $payment->foreign_amount_minor,
            'exchange_difference_ore' => $payment->exchange_difference_ore,
            'paid_on' => $payment->paid_on->toDateString(),
        ];
    }

    /** @return ?array<string, mixed> */
    public static function supplierInvoiceApproved(string $invoiceId): ?array
    {
        $invoice = SupplierInvoice::query()->find($invoiceId);

        if (! $invoice instanceof SupplierInvoice) {
            return null;
        }

        return [
            'supplier_invoice_id' => $invoice->id,
            'number' => $invoice->number,
            'supplier_id' => $invoice->supplier_id,
            'kind' => $invoice->kind,
            'total_ore' => $invoice->total_ore->ore,
            'currency' => 'SEK',
            'invoice_currency' => $invoice->invoice_currency,
            'due_date' => $invoice->due_date->toDateString(),
        ];
    }

    /**
     * Must run inside the company. Who was told is not included: fetch the
     * shifts for the period with planning:view.
     *
     * @return ?array<string, mixed>
     */
    public static function planningPublished(int $publicationId): ?array
    {
        $publication = Publication::query()->find($publicationId);

        if (! $publication instanceof Publication) {
            return null;
        }

        return [
            'publication_id' => $publication->id,
            'from' => $publication->from->toDateString(),
            'to' => $publication->to->toDateString(),
            'published_at' => $publication->published_at->toAtomString(),
            'shift_count' => $publication->shift_count,
        ];
    }

    /**
     * Built from the event alone: dates, ids and the attested minutes.
     *
     * @return array<string, mixed>
     */
    public static function planningAttested(PeriodAttested $event): array
    {
        return [
            'from' => $event->from,
            'to' => $event->to,
            'attestation_ids' => $event->attestationIds,
            'employee_ids' => $event->employeeIds,
            'attested_minutes' => $event->attestedMinutes,
        ];
    }

    /**
     * Must run inside the company. The file name is left out (it is free
     * text chosen by the sender); the receiver fetches the import's report
     * in NONOX.
     *
     * @return ?array<string, mixed>
     */
    public static function sieUploaded(string $uploadId): ?array
    {
        $upload = ConnectSftpUpload::query()->with('sieImport')->find($uploadId);

        if (! $upload instanceof ConnectSftpUpload) {
            return null;
        }

        $import = $upload->sieImport;

        return [
            'upload_id' => $upload->id,
            'status' => $upload->status,
            'size' => $upload->size,
            'sha256' => $upload->sha256,
            'received_at' => $upload->created_at->toAtomString(),
            'sie_import_id' => $upload->sie_import_id,
            'can_be_confirmed' => $import?->canBeConfirmed() ?? false,
            'vouchers' => $import === null ? null : (int) ($import->report['vouchers'] ?? 0),
            'blockers' => $import === null ? null : count($import->blockers()),
        ];
    }

    /**
     * Must run inside the company. The movements with their article,
     * location, signed quantity and the balance after; no costs and no
     * free text.
     *
     * @return ?array<string, mixed>
     */
    public static function stockChanged(StockChanged $event): ?array
    {
        $movements = StockMovement::query()->whereKey($event->movementIds)->orderBy('id')->get(['id', 'article_id', 'location_id', 'quantity_milli', 'type', 'occurred_on', 'source_type', 'source_id']);

        if ($movements->isEmpty()) {
            return null;
        }

        $levels = StockLevel::query()->whereIn('article_id', $event->articleIds)->get(['article_id', 'location_id', 'quantity_milli'])
            ->mapWithKeys(fn (StockLevel $level): array => ["{$level->article_id}:{$level->location_id}" => $level->quantity_milli]);

        return [
            'type' => $event->type,
            'article_ids' => $event->articleIds,
            'movements' => $movements->map(fn (StockMovement $movement): array => [
                'movement_id' => $movement->id,
                'article_id' => $movement->article_id,
                'location_id' => $movement->location_id,
                'quantity' => Quantity::decimal($movement->quantity_milli),
                'balance' => Quantity::decimal((int) ($levels["{$movement->article_id}:{$movement->location_id}"] ?? 0)),
                'occurred_on' => $movement->occurred_on->toDateString(),
            ])->all(),
        ];
    }

    /**
     * Must run inside the company. Ids, the time, the method and the hash
     * of the signed PDF; never the signer's name, address or IP address.
     * The order (and invoice draft) may still be on its way: the follow-up
     * runs after the signature (ADR-046).
     *
     * @return ?array<string, mixed>
     */
    public static function quoteSigned(int $signingRequestId): ?array
    {
        $request = SigningRequest::query()->with('quote')->find($signingRequestId);

        if (! $request instanceof SigningRequest || $request->signed_at === null || $request->quote === null) {
            return null;
        }

        return [
            'signing_request_id' => $request->id,
            'quote_id' => $request->sales_document_id,
            'quote_number' => $request->quote->number,
            'customer_id' => $request->quote->customer_id,
            'total_ore' => $request->quote->total_ore->ore,
            'currency' => 'SEK',
            'deal_id' => $request->deal_id,
            'signed_at' => $request->signed_at->toAtomString(),
            'signature_method' => $request->signature_method,
            'document_sha256' => $request->document_sha256,
        ];
    }
}
