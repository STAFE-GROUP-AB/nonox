<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customer and supplier invoices that are open on a given day, with what
 * is left of them in kronor as booked (at the invoice's own rate) and, for
 * an invoice in another currency, in that currency. Payments and credit
 * invoices dated after the day do not count, so the answer for the last day
 * of a fiscal year stays the same when the next year's payments come in.
 *
 * A customer invoice with a credit invoice for part of it is open for the
 * rest (ADR-025). The same rules hold under fakturametoden and kontantmetoden;
 * the kronor are the kronor the invoice is, or would be, booked at.
 *
 * A constant number of queries for each side, whatever the number of invoices.
 */
final class OpenInvoices
{
    /**
     * @return list<array{invoice: Invoice, open: int, foreign: ?int}>
     */
    public static function customer(CarbonImmutable $day, bool $foreignOnly = false, bool $withRows = false): array
    {
        $date = $day->toDateString();
        $credits = fn ($query) => $query->whereNotNull('number')->whereDate('invoice_date', '<=', $date);

        $invoices = Invoice::query()
            ->where('kind', Invoice::INVOICE)
            ->whereNotNull('number')
            ->whereDate('invoice_date', '<=', $date)
            ->when($foreignOnly, fn ($query) => $query->where('invoice_currency', '<>', 'SEK'))
            // Settled invoices count only when the settling came after the day; a credited one may have a rest.
            ->where(fn ($query) => $query->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Credited->value])
                ->orWhereHas('payments', fn ($payments) => $payments->whereDate('paid_on', '>', $date)))
            ->with([
                'payments' => fn ($query) => $query->whereDate('paid_on', '<=', $date),
                'creditInvoices' => $credits,
                ...($withRows ? ['rows.account', 'creditInvoices.rows.account'] : []),
            ])
            ->orderBy('number')
            ->get();

        $open = [];

        foreach ($invoices as $invoice) {
            $settled = (int) $invoice->payments->sum(fn (InvoicePayment $payment): int => $payment->amount_ore->ore - (int) $payment->exchange_difference_ore);
            $kronor = $invoice->total_ore->ore + (int) $invoice->creditInvoices->sum(fn (Invoice $credit): int => $credit->total_ore->ore) - $settled;
            $foreign = $invoice->isForeign()
                ? (int) $invoice->foreign_total_minor + (int) $invoice->creditInvoices->sum(fn (Invoice $credit): int => (int) $credit->foreign_total_minor) - (int) $invoice->payments->sum(fn (InvoicePayment $payment): int => (int) $payment->foreign_amount_minor)
                : null;

            if (($foreign ?? $kronor) !== 0) {
                $open[] = ['invoice' => $invoice, 'open' => $kronor, 'foreign' => $foreign];
            }
        }

        return $open;
    }

    /**
     * Supplier invoices and credit notes (negative) open on the day. A credit
     * note offset against an invoice is settled by its offset payments.
     *
     * @return list<array{invoice: SupplierInvoice, open: int, foreign: ?int}>
     */
    public static function supplier(CarbonImmutable $day, bool $foreignOnly = false, bool $withRows = false): array
    {
        $date = $day->toDateString();

        $invoices = SupplierInvoice::query()
            ->whereNotNull('number')
            ->whereDate('invoice_date', '<=', $date)
            ->when($foreignOnly, fn ($query) => $query->where('invoice_currency', '<>', 'SEK'))
            ->where(fn ($query) => $query->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value])
                ->orWhere(fn (Builder $paid) => $paid->where('status', SupplierInvoiceStatus::Paid->value)->whereHas('payments', fn ($payments) => $payments->whereDate('paid_on', '>', $date))))
            ->with([
                'supplier',
                'payments' => fn ($query) => $query->whereDate('paid_on', '<=', $date),
                ...($withRows ? ['rows.account', 'rows.costCenter', 'rows.project'] : []),
            ])
            ->orderBy('number')
            ->get();

        $open = [];

        foreach ($invoices as $invoice) {
            $kronor = $invoice->total_ore->ore - (int) $invoice->payments->sum(fn (SupplierPayment $payment): int => $payment->amount_ore->ore - (int) $payment->exchange_difference_ore);
            $foreign = $invoice->isForeign()
                ? (int) $invoice->foreign_total_minor - (int) $invoice->payments->sum(fn (SupplierPayment $payment): int => (int) $payment->foreign_amount_minor)
                : null;

            if (($foreign ?? $kronor) !== 0) {
                $open[] = ['invoice' => $invoice, 'open' => $kronor, 'foreign' => $foreign];
            }
        }

        return $open;
    }
}
