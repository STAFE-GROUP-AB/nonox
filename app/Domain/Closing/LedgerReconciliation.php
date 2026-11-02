<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer or supplier ledger (kundreskontra, leverantörsreskontra) on
 * any day, against the balance of 1510 or 2440 that day: the open invoices
 * as OpenInvoices rebuilds them from issue, payment, credit and write-off
 * dates, their total, the account balance from the posted vouchers dated up
 * to the day, and the difference. The closing checklist links here for the
 * last day of the fiscal year (#57).
 *
 * Bokföringslagen (1999:1078) 4 kap. 1-2 §§ and 5 kap. 1 § require current,
 * systematic bookkeeping under good accounting practice; no explicit rule
 * for a ledger as of a date has been found (enligt granskning 2026-11-02,
 * juristgranskning återstår (AUD-FAKT-12, #57)).
 *
 * Under kontantmetoden the invoices reach 1510 and 2440 only at year end
 * (UnpaidInvoices), apart from EU trade booked at issue, so a difference
 * during the year is expected there.
 */
final class LedgerReconciliation
{
    public const string CUSTOMERS = 'kund';

    public const string SUPPLIERS = 'leverantor';

    /**
     * @return array{items: list<array{title: string, name: string, date: string, open: int}>, ledger: int, account: int, balance: int, difference: int}
     */
    public static function on(string $side, CarbonImmutable $day): array
    {
        $customers = $side !== self::SUPPLIERS;
        $items = [];

        if ($customers) {
            foreach (OpenInvoices::customer($day) as ['invoice' => $invoice, 'open' => $open]) {
                $items[] = ['title' => $invoice->title(), 'name' => (string) ($invoice->customer_snapshot['name'] ?? ''), 'date' => $invoice->invoice_date->toDateString(), 'open' => $open];
            }
        } else {
            foreach (OpenInvoices::supplier($day) as ['invoice' => $invoice, 'open' => $open]) {
                $items[] = ['title' => $invoice->title(), 'name' => $invoice->supplierName(), 'date' => $invoice->invoice_date->toDateString(), 'open' => $open];
            }
        }

        $account = $customers ? InvoiceBooking::RECEIVABLES : SupplierInvoiceBooking::PAYABLES;
        $balance = (int) VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '<=', $day->toDateString())
            ->where('accounts.number', $account)
            ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
        // A liability is shown as a positive amount, like the ledger.
        $balance = $customers ? $balance : -$balance;
        $ledger = array_sum(array_column($items, 'open'));

        return ['items' => $items, 'ledger' => $ledger, 'account' => $account, 'balance' => $balance, 'difference' => $ledger - $balance];
    }
}
