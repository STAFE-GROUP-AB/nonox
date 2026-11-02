<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;

/**
 * Unrealised exchange differences at year-end (ADR-025): customer invoices
 * (1510) and supplier invoices (2440) in another currency that are open on
 * the last day of the fiscal year are valued at Riksbanken's rate that day.
 * The difference to the kronor booked is a gain on 3960 or a loss on 7960,
 * booked on the last day and reversed on the first day of the next year,
 * so a later payment still settles the kronor booked on the invoice.
 *
 * Only under fakturametoden: under kontantmetoden the invoices are not on
 * 1510 and 2440 during the year.
 *
 * A constant number of queries: the open invoices with their payments up to
 * the last day, and one rate per currency.
 *
 * TODO(legal-verify): BFNAR 2016:10 (K2) and BFNAR 2012:1 (K3, chapter 30)
 * on valuing receivables and liabilities in foreign currency at the closing
 * rate; whether Riksbanken's mid rate is an acceptable closing rate; and
 * how a company on kontantmetoden values the unpaid invoices it books at
 * year-end.
 */
final class CurrencyRevaluation
{
    /**
     * @param  array<string, ExchangeRate>  $rates  closing rates by currency; Riksbanken's are fetched for the rest
     * @return array{applies: bool, lines: list<array{kind: string, title: string, currency: string, foreign: int, booked: int, closing: int, difference: int}>, missing: list<string>, rates: array<string, ExchangeRate>}
     */
    public static function for(Company $company, FiscalYear $fiscalYear, array $rates = []): array
    {
        if ($company->accounting_method !== AccountingMethod::Fakturametoden) {
            return ['applies' => false, 'lines' => [], 'missing' => [], 'rates' => []];
        }

        $end = $fiscalYear->end_date->toDateString();
        $open = [];

        $invoices = Invoice::query()
            ->where('invoice_currency', '<>', 'SEK')
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value, InvoiceStatus::Credited->value])
            ->where('kind', Invoice::INVOICE)
            ->whereDate('invoice_date', '<=', $end)
            ->with(['payments' => fn ($query) => $query->whereDate('paid_on', '<=', $end), 'creditInvoices' => fn ($query) => $query->whereNotNull('number')->whereDate('invoice_date', '<=', $end)])
            ->get();

        foreach ($invoices as $invoice) {
            if (! $invoice->isForeign() || $invoice->creditInvoices->isNotEmpty()) {
                continue;
            }

            $foreign = (int) $invoice->foreign_total_minor - (int) $invoice->payments->sum(fn (InvoicePayment $payment): int => (int) $payment->foreign_amount_minor);
            $booked = $invoice->total_ore->ore - (int) $invoice->payments->sum(fn (InvoicePayment $payment): int => $payment->amount_ore->ore - $payment->exchange_difference_ore);

            if ($foreign !== 0) {
                $open[] = ['kind' => 'receivable', 'title' => $invoice->title().', '.($invoice->customer_snapshot['name'] ?? ''), 'currency' => $invoice->invoice_currency, 'foreign' => $foreign, 'booked' => $booked];
            }
        }

        $supplierInvoices = SupplierInvoice::query()
            ->where('invoice_currency', '<>', 'SEK')
            ->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value, SupplierInvoiceStatus::Paid->value])
            ->whereDate('invoice_date', '<=', $end)
            ->with(['payments' => fn ($query) => $query->whereDate('paid_on', '<=', $end)])
            ->get();

        foreach ($supplierInvoices as $invoice) {
            if (! $invoice->isForeign()) {
                continue;
            }

            $foreign = (int) $invoice->foreign_total_minor - (int) $invoice->payments->sum(fn (SupplierPayment $payment): int => (int) $payment->foreign_amount_minor);
            $booked = $invoice->total_ore->ore - (int) $invoice->payments->sum(fn (SupplierPayment $payment): int => $payment->amount_ore->ore - $payment->exchange_difference_ore);

            if ($foreign !== 0) {
                $open[] = ['kind' => 'payable', 'title' => $invoice->title().', '.$invoice->supplierName(), 'currency' => $invoice->invoice_currency, 'foreign' => $foreign, 'booked' => $booked];
            }
        }

        $missing = [];

        foreach (array_unique(array_column($open, 'currency')) as $currency) {
            $rate = $rates[$currency] ?? app(RiksbankRates::class)->rate($currency, $end);

            if ($rate instanceof ExchangeRate) {
                $rates[$currency] = $rate;
            } else {
                $missing[] = $currency;
            }
        }

        $lines = [];

        foreach ($open as $line) {
            $rate = $rates[$line['currency']] ?? null;

            if (! $rate instanceof ExchangeRate) {
                continue;
            }

            $closing = $rate->toOre($line['foreign']);
            // A receivable worth more is a gain; a liability worth more is a loss.
            $difference = $line['kind'] === 'receivable' ? $closing - $line['booked'] : $line['booked'] - $closing;
            $lines[] = [...$line, 'closing' => $closing, 'difference' => $difference];
        }

        return ['applies' => $open !== [], 'lines' => $lines, 'missing' => $missing, 'rates' => $rates];
    }

    /**
     * The voucher rows on the last day, signed öre, debit positive: the
     * change of 1510 and 2440, gains on 3960 and losses on 7960.
     *
     * @param  list<array{kind: string, difference: int}>  $lines
     * @return list<array{account: int, amount: int, description?: ?string}>
     */
    public static function rows(array $lines): array
    {
        $receivables = 0;
        $payables = 0;
        $gains = 0;
        $losses = 0;

        foreach ($lines as $line) {
            // A gain makes a receivable larger (debit 1510) and a liability smaller (debit 2440).
            if ($line['kind'] === 'receivable') {
                $receivables += $line['difference'];
            } else {
                $payables += $line['difference'];
            }

            if ($line['difference'] > 0) {
                $gains += $line['difference'];
            }

            if ($line['difference'] < 0) {
                $losses -= $line['difference'];
            }
        }

        return array_values(array_filter([
            ['account' => InvoiceBooking::RECEIVABLES, 'amount' => $receivables],
            ['account' => SupplierInvoiceBooking::PAYABLES, 'amount' => $payables],
            ['account' => InvoiceBooking::EXCHANGE_GAIN, 'amount' => -$gains],
            ['account' => InvoiceBooking::EXCHANGE_LOSS, 'amount' => $losses],
        ], fn (array $row): bool => $row['amount'] !== 0));
    }

    /** @param  list<array{difference: int}>  $lines */
    public static function net(array $lines): int
    {
        return array_sum(array_column($lines, 'difference'));
    }
}
