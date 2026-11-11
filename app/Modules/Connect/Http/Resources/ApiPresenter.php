<?php

declare(strict_types=1);

namespace App\Modules\Connect\Http\Resources;

use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceRow;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherRow;
use App\Models\VoucherSeries;
use App\Modules\Peppol\Models\PeppolTransmission;
use DateTimeInterface;

/**
 * How records look in the API (ADR-032). Money is whole öre as integers,
 * with the currency beside it; dates are ISO 8601 (YYYY-MM-DD for
 * accounting dates). Personal numbers are never included: only whether one
 * is on file.
 */
final class ApiPresenter
{
    /** @return array<string, mixed> */
    public static function company(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'org_number' => $company->org_number,
            'legal_form' => $company->legal_form->value,
            'vat_number' => $company->vat_number,
            'vat_registered' => (bool) $company->vat_registered,
            'vat_period' => $company->vat_period->value,
            'accounting_method' => $company->accounting_method->value,
            'reporting_framework' => $company->reporting_framework->value,
            'fiscal_year_start_month' => $company->fiscal_year_start_month,
            'base_currency' => $company->base_currency,
            'locked_until_date' => self::date($company->locked_until_date),
            'archived' => $company->archived_at !== null,
            'address' => [
                'line1' => $company->address_line1,
                'line2' => $company->address_line2,
                'postal_code' => $company->postal_code,
                'city' => $company->city,
                'country' => $company->country,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function account(Account $account): array
    {
        return [
            'number' => $account->number,
            'name' => $account->name,
            'type' => $account->type->value,
            'is_active' => $account->is_active,
            'sru_code' => $account->sru_code,
            'requires_cost_center' => (bool) $account->requires_cost_center,
            'requires_project' => (bool) $account->requires_project,
            'updated_at' => self::time($account->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function fiscalYear(FiscalYear $year): array
    {
        return [
            'id' => $year->id,
            'start_date' => self::date($year->start_date),
            'end_date' => self::date($year->end_date),
            'status' => $year->status->value,
            'updated_at' => self::time($year->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function series(VoucherSeries $series): array
    {
        return [
            'code' => $series->code,
            'name' => $series->name,
            'is_default' => $series->is_default,
        ];
    }

    /**
     * Needs rows.account, rows.costCenter, rows.project, series and attachments loaded.
     *
     * @return array<string, mixed>
     */
    public static function voucher(Voucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'series' => $voucher->series?->code,
            'number' => $voucher->number,
            'reference' => $voucher->isPosted() ? $voucher->series?->code.$voucher->number : null,
            'status' => $voucher->status->value,
            'fiscal_year_id' => $voucher->fiscal_year_id,
            'voucher_date' => self::date($voucher->voucher_date),
            'description' => $voucher->description,
            'source' => $voucher->source->value,
            'corrects_voucher_id' => $voucher->corrects_voucher_id,
            'corrected_by_voucher_id' => $voucher->corrected_by_voucher_id,
            'posted_at' => self::time($voucher->posted_at),
            'hash' => $voucher->hash,
            'currency' => 'SEK',
            'rows' => $voucher->rows->map(fn (VoucherRow $row): array => [
                'account' => $row->account?->number,
                'debit_ore' => $row->debit_ore->ore,
                'credit_ore' => $row->credit_ore->ore,
                'description' => $row->description,
                'cost_center' => $row->costCenter?->code,
                'project' => $row->project?->code,
            ])->values()->all(),
            'attachments' => $voucher->attachments->map(fn (VoucherAttachment $attachment): array => self::attachment($attachment))->values()->all(),
            'created_at' => self::time($voucher->created_at),
            'updated_at' => self::time($voucher->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function attachment(VoucherAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'sha256' => $attachment->sha256,
            'created_at' => self::time($attachment->created_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'number' => $customer->number,
            'name' => $customer->name,
            'org_number' => $customer->org_number,
            // Never the number itself (ADR-032): only that the customer has one.
            'has_personal_number' => $customer->getRawOriginal('personal_number') !== null,
            'vat_number' => $customer->vat_number,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'address' => [
                'line1' => $customer->address_line1,
                'line2' => $customer->address_line2,
                'postal_code' => $customer->postal_code,
                'city' => $customer->city,
                'country' => $customer->country,
            ],
            'payment_terms_days' => $customer->payment_terms_days,
            'is_active' => $customer->is_active,
            'reverse_charge_construction' => (bool) $customer->reverse_charge_construction,
            'foreign_sale' => $customer->foreign_sale?->value,
            'currency' => $customer->currency,
            'processing_restricted' => $customer->processing_restricted_at !== null,
            'created_at' => self::time($customer->created_at),
            'updated_at' => self::time($customer->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function supplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'number' => $supplier->number,
            'name' => $supplier->name,
            'org_number' => $supplier->org_number,
            'vat_number' => $supplier->vat_number,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'address' => [
                'line1' => $supplier->address_line1,
                'postal_code' => $supplier->postal_code,
                'city' => $supplier->city,
                'country' => $supplier->country,
            ],
            'bankgiro' => $supplier->bankgiro,
            'plusgiro' => $supplier->plusgiro,
            'iban' => $supplier->iban,
            'bic' => $supplier->bic,
            'payment_terms_days' => $supplier->payment_terms_days,
            'default_account' => $supplier->defaultAccount?->number,
            'is_active' => $supplier->is_active,
            'processing_restricted' => $supplier->processing_restricted_at !== null,
            'created_at' => self::time($supplier->created_at),
            'updated_at' => self::time($supplier->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function unit(Unit $unit, int $defaultUnitId): array
    {
        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'dimension' => $unit->dimension->value,
            'rec20_code' => $unit->rec20_code,
            'aliases' => $unit->aliases,
            'is_default' => $unit->id === $defaultUnitId,
            'is_archived' => $unit->isArchived(),
        ];
    }

    /**
     * Needs account loaded.
     *
     * @return array<string, mixed>
     */
    public static function article(Article $article): array
    {
        return [
            'id' => $article->id,
            'number' => $article->number,
            'name' => $article->name,
            'unit' => $article->unit,
            'unit_id' => $article->sales_unit_id ?? $article->stock_unit_id,
            'price_ore' => $article->price_ore->ore,
            'currency' => 'SEK',
            'vat_rate' => $article->vat_rate,
            'account' => $article->account?->number,
            'is_active' => $article->is_active,
            'created_at' => self::time($article->created_at),
            'updated_at' => self::time($article->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function dimensionObject(CostCenter|Project $object): array
    {
        return [
            'id' => $object->id,
            'code' => $object->code,
            'name' => $object->name,
            'is_active' => $object->is_active,
            'created_at' => self::time($object->created_at),
            'updated_at' => self::time($object->updated_at),
        ];
    }

    /**
     * The latest sending through Peppol (ADR-050), or null when the invoice
     * has not been sent that way. Needs peppolTransmissions loaded; the
     * receiver's Peppol ID is left out.
     *
     * @return ?array{transmission_id: string, status: string, document_type: string, queued_at: ?string, sent_at: ?string, delivered_at: ?string, failed_at: ?string, attempts: int}
     */
    private static function peppol(Invoice $invoice): ?array
    {
        if (! $invoice->relationLoaded('peppolTransmissions')) {
            return null;
        }

        /** @var ?PeppolTransmission $latest */
        $latest = $invoice->getRelation('peppolTransmissions')->sortByDesc('queued_at')->first();

        return $latest === null ? null : [
            'transmission_id' => $latest->id,
            'status' => $latest->status->value,
            'document_type' => $latest->document_type,
            'queued_at' => self::time($latest->queued_at),
            'sent_at' => self::time($latest->sent_at),
            'delivered_at' => self::time($latest->delivered_at),
            'failed_at' => self::time($latest->failed_at),
            'attempts' => $latest->attempts,
        ];
    }

    /**
     * Needs rows.account, rows.article, payments.account and customer loaded.
     * The customer is shown as it was when the invoice was issued; a
     * personal number is never part of it.
     *
     * @return array<string, mixed>
     */
    public static function invoice(Invoice $invoice): array
    {
        $snapshot = $invoice->customer_snapshot ?? ($invoice->customer?->snapshot() ?? []);

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'external_ref' => $invoice->external_ref,
            'kind' => $invoice->kind,
            'status' => $invoice->status->value,
            'credits_invoice_id' => $invoice->credits_invoice_id,
            'customer_id' => $invoice->customer_id,
            'customer' => [
                'number' => $snapshot['number'] ?? null,
                'name' => $snapshot['name'] ?? null,
                'org_number' => $snapshot['org_number'] ?? null,
                'vat_number' => $snapshot['vat_number'] ?? null,
                'email' => $snapshot['email'] ?? null,
                'address' => [
                    'line1' => $snapshot['address_line1'] ?? null,
                    'line2' => $snapshot['address_line2'] ?? null,
                    'postal_code' => $snapshot['postal_code'] ?? null,
                    'city' => $snapshot['city'] ?? null,
                    'country' => $snapshot['country'] ?? null,
                ],
            ],
            'invoice_date' => self::date($invoice->invoice_date),
            'due_date' => self::date($invoice->due_date),
            'delivery_date' => self::date($invoice->delivery_date),
            'your_reference' => $invoice->your_reference,
            'our_reference' => $invoice->our_reference,
            'notes' => $invoice->notes,
            'reverse_charge' => $invoice->reverse_charge,
            'foreign_sale' => $invoice->foreign_sale?->value,
            'tax_reduction' => $invoice->tax_reduction,
            'tax_reduction_ore' => $invoice->tax_reduction_ore->ore,
            'property_designation' => $invoice->property_designation,
            'currency' => 'SEK',
            'net_ore' => $invoice->net_ore->ore,
            'vat_ore' => $invoice->vat_ore->ore,
            'rounding_ore' => $invoice->rounding_ore->ore,
            'total_ore' => $invoice->total_ore->ore,
            'paid_ore' => $invoice->paid_ore->ore,
            'remaining_ore' => $invoice->remaining()->ore,
            // The currency the invoice is written in; the *_ore amounts above are always kronor as booked.
            'invoice_currency' => $invoice->invoice_currency,
            // Kronor per unit of the invoice's currency, in millionths (11.2345 is 11234500).
            'exchange_rate_micros' => $invoice->exchange_rate_micros,
            'exchange_rate_date' => self::date($invoice->exchange_rate_date),
            'exchange_rate_source' => $invoice->exchange_rate_source,
            'foreign_net_minor' => $invoice->foreign_net_minor,
            'foreign_vat_minor' => $invoice->foreign_vat_minor,
            'foreign_total_minor' => $invoice->foreign_total_minor,
            'foreign_paid_minor' => $invoice->isForeign() ? $invoice->foreign_paid_minor : null,
            'foreign_remaining_minor' => $invoice->foreignRemaining(),
            'ocr' => $invoice->ocr,
            'voucher_id' => $invoice->voucher_id,
            'issued_at' => self::time($invoice->issued_at),
            'sent_at' => self::time($invoice->sent_at),
            'sent_to' => $invoice->sent_to,
            'peppol' => self::peppol($invoice),
            'rows' => $invoice->rows->map(fn (InvoiceRow $row): array => [
                'article' => $row->article?->number,
                'description' => $row->description,
                'quantity' => $row->quantity,
                'unit' => $row->unit,
                'unit_id' => $row->unit_id,
                'unit_price_ore' => $row->unit_price_ore->ore,
                // Hundredths of the invoice's currency, as written on the invoice; equal to the öre for SEK.
                'unit_price_minor' => $row->foreign_unit_price_minor ?? $row->unit_price_ore->ore,
                'discount_bp' => $row->discount_bp,
                'vat_rate' => $row->vat_rate,
                'account' => $row->account?->number,
                'net_ore' => $row->net_ore->ore,
                'net_minor' => $row->foreign_net_minor ?? $row->net_ore->ore,
                'labour' => $row->is_labour,
            ])->values()->all(),
            'payments' => $invoice->payments->map(fn (InvoicePayment $payment): array => self::invoicePayment($payment))->values()->all(),
            'created_at' => self::time($invoice->created_at),
            'updated_at' => self::time($invoice->updated_at),
        ];
    }

    /**
     * Needs account loaded.
     *
     * @return array<string, mixed>
     */
    public static function invoicePayment(InvoicePayment $payment): array
    {
        return [
            'id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'paid_on' => self::date($payment->paid_on),
            'amount_ore' => $payment->amount_ore->ore,
            'currency' => 'SEK',
            'foreign_amount_minor' => $payment->foreign_amount_minor,
            // Kronor received minus kronor of the receivable settled: positive a gain (3960), negative a loss (7960).
            'exchange_difference_ore' => $payment->exchange_difference_ore,
            'account' => $payment->account?->number,
            'voucher_id' => $payment->voucher_id,
            'created_at' => self::time($payment->created_at),
            'updated_at' => self::time($payment->updated_at),
        ];
    }

    /**
     * Needs rows.account, rows.costCenter, rows.project, payments.account and supplier loaded.
     *
     * @return array<string, mixed>
     */
    public static function supplierInvoice(SupplierInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'external_ref' => $invoice->external_ref,
            'kind' => $invoice->kind,
            'status' => $invoice->status->value,
            'supplier_id' => $invoice->supplier_id,
            'supplier_name' => $invoice->supplierName(),
            'invoice_number' => $invoice->invoice_number,
            'ocr' => $invoice->ocr,
            'invoice_date' => self::date($invoice->invoice_date),
            'due_date' => self::date($invoice->due_date),
            'description' => $invoice->description,
            'vat_treatment' => $invoice->vat_treatment->value,
            'currency' => 'SEK',
            'total_ore' => $invoice->total_ore->ore,
            'vat_ore' => $invoice->vat_ore->ore,
            'paid_ore' => $invoice->paid_ore->ore,
            'remaining_ore' => $invoice->remaining()->ore,
            'invoice_currency' => $invoice->invoice_currency,
            'foreign_total_minor' => $invoice->foreign_total_minor,
            'foreign_paid_minor' => $invoice->isForeign() ? $invoice->foreign_paid_minor : null,
            // Kronor per unit of the invoice's currency, in millionths (9.8264 is 9826400).
            'exchange_rate_micros' => $invoice->exchange_rate_micros,
            'file' => $invoice->file_path === null ? null : [
                'name' => $invoice->file_name,
                'mime' => $invoice->file_mime,
                'size' => $invoice->file_size,
                'sha256' => $invoice->file_sha256,
            ],
            'voucher_id' => $invoice->voucher_id,
            'registered_at' => self::time($invoice->registered_at),
            'approved_at' => self::time($invoice->approved_at),
            'rows' => $invoice->rows->map(fn (SupplierInvoiceRow $row): array => [
                'account' => $row->account?->number,
                'description' => $row->description,
                'amount_ore' => $row->amount_ore->ore,
                'vat_rate' => $row->vat_rate,
                'cost_center' => $row->costCenter?->code,
                'project' => $row->project?->code,
            ])->values()->all(),
            'payments' => $invoice->payments->map(fn (SupplierPayment $payment): array => self::supplierPayment($payment))->values()->all(),
            'created_at' => self::time($invoice->created_at),
            'updated_at' => self::time($invoice->updated_at),
        ];
    }

    /**
     * Needs account loaded.
     *
     * @return array<string, mixed>
     */
    public static function supplierPayment(SupplierPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'supplier_invoice_id' => $payment->supplier_invoice_id,
            'paid_on' => self::date($payment->paid_on),
            'amount_ore' => $payment->amount_ore->ore,
            'currency' => 'SEK',
            'foreign_amount_minor' => $payment->foreign_amount_minor,
            'exchange_difference_ore' => $payment->exchange_difference_ore,
            'account' => $payment->account?->number,
            'voucher_id' => $payment->voucher_id,
            'created_at' => self::time($payment->created_at),
            'updated_at' => self::time($payment->updated_at),
        ];
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    private static function time(?DateTimeInterface $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }
}
