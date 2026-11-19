<?php

declare(strict_types=1);

namespace App\Domain\Signing\Retention;

use App\Events\VoucherPosted;
use App\Models\SigningEnvelope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * When a voucher is posted for an invoice, a payment of one, a credit note of
 * one or a write-off of one, made from a signed quote (directly, through its
 * order or through a work order made from either), agreement or ÄTA, that
 * envelope's retention moves later if the booking falls in a later fiscal
 * year (ADR-046 S16, review 2026-11-19): räkenskapsinformation is kept until
 * 31 December seven years after the fiscal year of the business event. A
 * fixed number of queries per voucher; the prune checks the same rule again
 * (SigningRetention::requiredForMany) before it deletes anything.
 */
final class ExtendRetentionOnBooking
{
    public function __invoke(VoucherPosted $event): void
    {
        CurrentCompany::run($event->companyId, function () use ($event): void {
            $company = $event->companyId;
            $booked = DB::table('invoices')->where('company_id', $company)
                ->where(fn ($query) => $query->where('voucher_id', $event->voucherId)->orWhere('write_off_voucher_id', $event->voucherId))
                ->get(['id', 'credits_invoice_id']);
            $invoiceIds = $booked->pluck('id')
                ->merge($booked->pluck('credits_invoice_id')->filter())
                ->merge(DB::table('invoice_payments')->where('company_id', $company)->where('voucher_id', $event->voucherId)->pluck('invoice_id'))
                ->unique()->values()->all();

            if ($invoiceIds === []) {
                return;
            }

            $workOrderIds = DB::table('work_order_invoices')->where('company_id', $company)->whereIn('invoice_id', $invoiceIds)->get(['work_order_id', 'change_order_ids']);
            $documents = DB::table('sales_documents')->where('company_id', $company)
                ->where(fn ($query) => $query->whereIn('invoice_id', $invoiceIds)
                    ->orWhereIn('id', DB::table('work_orders')->where('company_id', $company)->whereIn('id', $workOrderIds->pluck('work_order_id')->all())->whereNotNull('sales_document_id')->select('sales_document_id')))
                ->get(['id', 'from_quote_id']);
            $quotes = $documents->map(fn (object $row): string => (string) ($row->from_quote_id ?? $row->id))->unique()->values()->all();
            $agreements = DB::table('crm_agreements')->where('company_id', $company)
                ->whereIn('recurring_invoice_id', DB::table('invoices')->whereIn('id', $invoiceIds)->whereNotNull('recurring_invoice_id')->select('recurring_invoice_id'))
                ->pluck('id')->all();
            $changeOrders = $workOrderIds->pluck('change_order_ids')
                ->flatMap(fn (mixed $ids): array => is_string($ids) ? array_map('strval', (array) json_decode($ids, true)) : [])->unique()->values()->all();
            $signedFor = DB::table('crm_signing_requests')->where('company_id', $company)->whereNotNull('envelope_id')
                ->where(fn ($query) => $query->whereIn('invoice_id', $invoiceIds)->orWhereIn('order_id', $documents->pluck('id')->all()))
                ->pluck('envelope_id')->all();

            $envelopes = SigningEnvelope::query()
                ->where('status', 'COMPLETED')
                ->where('retention_class', SigningRetentionClass::Accounting->value)
                ->where(fn ($query) => $query
                    ->whereIn('sales_document_id', $quotes)
                    ->orWhereIn('agreement_id', $agreements)
                    ->orWhere(fn ($q) => $q->where('document_type', 'ATA')->whereIn('signable_id', $changeOrders))
                    ->orWhereIn('id', $signedFor))
                ->get();
            $required = SigningRetention::requiredForMany($envelopes);

            foreach ($envelopes as $envelope) {
                SigningRetention::refresh($envelope, required: $required[$envelope->id] ?? null, computed: true);
            }
        });
    }
}
