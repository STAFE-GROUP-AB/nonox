<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Support\Currency\ExchangeRate;
use App\Support\Currency\RiksbankRates;
use App\Support\Tenancy\CurrentCompany;

/**
 * Unrealised exchange differences at year-end (ADR-025): customer invoices
 * (1510) and supplier invoices (2440) in another currency that are open on
 * the last day of the fiscal year are valued at Riksbanken's rate that day.
 * The difference to the kronor booked is a gain on 3960 or a loss on 7960,
 * booked on the last day and reversed on the first day of the next year,
 * so a later payment still settles the kronor booked on the invoice.
 *
 * Under kontantmetoden the same invoices are first booked on 1510 and 2440
 * by the closing step for unpaid invoices (UnpaidInvoices), at the kronor
 * this class takes as booked, so they are valued the same way afterwards.
 * An invoice with a credit invoice for part of it is valued for the rest.
 *
 * Rule ("enligt AI-utredning 2026-11-02, juristgranskning återstår"): K2
 * (BFNAR 2016:10) punkt 13.5 and 17.8 and K3 (BFNAR 2012:1) punkt 30.3 say
 * monetary items in foreign currency "ska" be valued at the closing rate.
 * Bank accounts kept in another currency are not supported (ADR-025), so
 * there are none to value.
 *
 * A constant number of queries: the open invoices with their payments and
 * credit invoices up to the last day, and one rate per currency.
 *
 * TODO(legal-verify): whether Riksbanken's mid rate is an acceptable closing
 * rate (K2 names it in the commentary; the bank's buying rate for receivables
 * and selling rate for liabilities is the normal case), and the reversal on
 * the first day of the next year.
 */
final class CurrencyRevaluation
{
    /**
     * @param  array<string, ExchangeRate>  $rates  closing rates by currency; Riksbanken's are fetched for the rest
     * @return array{applies: bool, lines: list<array{kind: string, title: string, currency: string, foreign: int, booked: int, closing: int, difference: int}>, missing: list<string>, rates: array<string, ExchangeRate>}
     */
    public static function for(Company $company, FiscalYear $fiscalYear, array $rates = []): array
    {
        $end = $fiscalYear->end_date;
        $open = [];

        /** @var list<array{invoice: Invoice, open: int, foreign: ?int}> $customer */
        $customer = CurrentCompany::run($company, fn (): array => OpenInvoices::customer($end, foreignOnly: true));
        /** @var list<array{invoice: SupplierInvoice, open: int, foreign: ?int}> $supplier */
        $supplier = CurrentCompany::run($company, fn (): array => OpenInvoices::supplier($end, foreignOnly: true));

        foreach ($customer as ['invoice' => $invoice, 'open' => $booked, 'foreign' => $foreign]) {
            if ($foreign !== null) {
                $open[] = ['kind' => 'receivable', 'title' => $invoice->title().', '.($invoice->customer_snapshot['name'] ?? ''), 'currency' => $invoice->invoice_currency, 'foreign' => $foreign, 'booked' => $booked];
            }
        }

        foreach ($supplier as ['invoice' => $invoice, 'open' => $booked, 'foreign' => $foreign]) {
            if ($foreign !== null) {
                $open[] = ['kind' => 'payable', 'title' => $invoice->title().', '.$invoice->supplierName(), 'currency' => $invoice->invoice_currency, 'foreign' => $foreign, 'booked' => $booked];
            }
        }

        $missing = [];

        foreach (array_unique(array_column($open, 'currency')) as $currency) {
            $rate = $rates[$currency] ?? app(RiksbankRates::class)->rate($currency, $end->toDateString());

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
