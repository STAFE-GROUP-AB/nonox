<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Unpaid invoices at the end of the fiscal year under kontantmetoden
 * (ADR-013, ADR-011): during the year an invoice is booked only when it is
 * paid, but every customer and supplier invoice still unpaid on the last day
 * is booked then, as fakturametoden would have booked it: 1510 against the
 * revenue and output VAT, the costs and input VAT against 2440. The voucher
 * is reversed on the first day of the next year, so the payment later books
 * as usual under kontantmetoden and nothing is booked twice.
 *
 * Rule ("enligt AI-utredning 2026-11-02, juristgranskning återstår"):
 * Bokföringslagen 5 kap. 2 § ("Vid räkenskapsårets utgång ska dock samtliga
 * då obetalda fordringar och skulder bokföras") and BFNAR 2006:1 punkt 4.2
 * and 6.56-6.57.
 *
 * TODO(legal-verify): the reversal on the first day of the next year as the
 * method (BFL and BFNAR say what is booked at year-end, not how it is
 * undone); and in which VAT period the VAT on these entries is reported
 * (Mervärdesskattelag 2023:200, 7 kap.). It is booked on the usual VAT
 * accounts on the last day, so it follows the posting: in the return for
 * the period that holds the last day if that is not settled yet.
 *
 * Invoices in another currency are booked at their booking rate here;
 * CurrencyRevaluation then values them at the closing rate.
 */
final class UnpaidInvoices
{
    /**
     * The unpaid invoices on the last day and the rows of the two vouchers.
     * Applies only under kontantmetoden.
     *
     * @return array{applies: bool, customer: list<array{title: string, open: int}>, supplier: list<array{title: string, open: int}>, customerRows: list<array{account: int, amount: int}>, supplierRows: list<array{account: int, amount: int, cost_center?: ?string, project?: ?string}>}
     */
    public static function for(Company $company, FiscalYear $fiscalYear, bool $withRows = true): array
    {
        $none = ['applies' => false, 'customer' => [], 'supplier' => [], 'customerRows' => [], 'supplierRows' => []];

        if ($company->accounting_method !== AccountingMethod::Kontantmetoden) {
            return $none;
        }

        /** @var list<array{invoice: Invoice, open: int, foreign: ?int}> $customer */
        $customer = CurrentCompany::run($company, fn (): array => OpenInvoices::customer($fiscalYear->end_date, withRows: $withRows));
        /** @var list<array{invoice: SupplierInvoice, open: int, foreign: ?int}> $supplier */
        $supplier = CurrentCompany::run($company, fn (): array => OpenInvoices::supplier($fiscalYear->end_date, withRows: $withRows));
        // EU sales and acquisitions of goods are already on 1510 and 2440 since issue (AUD-FAKT-4, #50).
        $customer = array_values(array_filter($customer, fn (array $item): bool => ! InvoiceBooking::bookedAtIssue($item['invoice'], $company->accounting_method)));
        $supplier = array_values(array_filter($supplier, fn (array $item): bool => ! SupplierInvoiceBooking::bookedAtRegistration($item['invoice'], $company->accounting_method)));
        $customerLines = [];
        $supplierLines = [];

        if ($withRows) {
            foreach ($customer as ['invoice' => $invoice, 'open' => $open]) {
                $customerLines[] = self::customerLines($invoice, $open);
            }

            foreach ($supplier as ['invoice' => $invoice, 'open' => $open]) {
                $supplierLines[] = SupplierInvoiceBooking::payment($invoice, $invoice->rows, $open, SupplierInvoiceBooking::PAYABLES, cashMethod: true);
            }
        }

        return [
            'applies' => $customer !== [] || $supplier !== [],
            'customer' => array_map(fn (array $item): array => ['title' => $item['invoice']->title().', '.($item['invoice']->customer_snapshot['name'] ?? ''), 'open' => $item['open']], $customer),
            'supplier' => array_map(fn (array $item): array => ['title' => $item['invoice']->title().', '.$item['invoice']->supplierName(), 'open' => $item['open']], $supplier),
            'customerRows' => self::merge(array_merge(...$customerLines)),
            'supplierRows' => self::merge(array_merge(...$supplierLines)),
        ];
    }

    /** @param  list<array{open: int}>  $items */
    public static function total(array $items): int
    {
        return array_sum(array_column($items, 'open'));
    }

    /**
     * The receivable and the open part of the revenue, VAT and rounding of a
     * customer invoice and its credit invoices, as InvoiceBooking books them.
     *
     * @return list<array{account: int, amount: int}>
     */
    private static function customerLines(Invoice $invoice, int $open): array
    {
        // A credit balance (a credit of an invoice the customer had paid) is a share of the credits alone.
        $lines = $open < 0 ? [] : InvoiceBooking::revenue($invoice, $invoice->rows);
        $base = $open < 0 ? 0 : $invoice->total_ore->ore;

        foreach ($invoice->creditInvoices as $credit) {
            foreach (InvoiceBooking::revenue($credit, $credit->rows) as $account => $amount) {
                $lines[$account] = ($lines[$account] ?? 0) + $amount;
            }

            $base += $credit->total_ore->ore;
        }

        $lines = array_filter($lines);

        if ($open !== $base && $base !== 0) {
            $lines = array_map(fn (int $amount): int => BigDecimal::of($amount)->multipliedBy($open)->dividedBy($base, 0, RoundingMode::HalfUp)->toInt(), $lines);
            // The rounding remainder of the shares goes to the largest line, so the voucher balances.
            $remainder = -$open - array_sum($lines);

            if ($remainder !== 0 && $lines !== []) {
                $sizes = array_map('abs', $lines);
                $largest = array_search(max($sizes), $sizes, true);
                $lines[$largest] += $remainder;
            }
        }

        $rows = [['account' => InvoiceBooking::RECEIVABLES, 'amount' => $open]];

        foreach ($lines as $account => $amount) {
            $rows[] = ['account' => $account, 'amount' => $amount];
        }

        return $rows;
    }

    /**
     * One row per account, cost centre and project.
     *
     * @param  list<array{account: int, amount: int, description?: ?string, cost_center?: ?string, project?: ?string}>  $rows
     * @return list<array{account: int, amount: int, cost_center?: ?string, project?: ?string}>
     */
    private static function merge(array $rows): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $key = $row['account'].'|'.($row['cost_center'] ?? '').'|'.($row['project'] ?? '');
            $merged[$key] ??= array_filter(['account' => $row['account'], 'amount' => 0, 'cost_center' => $row['cost_center'] ?? null, 'project' => $row['project'] ?? null], fn (mixed $value): bool => $value !== null);
            $merged[$key]['amount'] += $row['amount'];
        }

        ksort($merged, SORT_NATURAL);

        return array_values(array_filter($merged, fn (array $row): bool => $row['amount'] !== 0));
    }
}
