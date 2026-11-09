<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Writes off the open part of an invoice as a confirmed customer loss
 * (konstaterad kundförlust), for example after a bankruptcy or a failed
 * collection. The seller may then reduce the output VAT already reported
 * (Mervärdesskattelag (2023:200) 8 kap. 16 §, 7 kap. 43 §) for the period
 * the loss is booked (7 kap. 44 §). The reduction is optional in law; here
 * it is always made, since the loss is confirmed.
 *
 * When the invoice is on 1510 (fakturametoden, or an EU sale under
 * kontantmetoden), a voucher in series B credits 1510 with the open part,
 * debits its share of the output VAT and books the rest on 6351. Under
 * kontantmetoden, when nothing was booked at issue, nothing is booked now
 * either: no revenue or VAT was reported. Either way the invoice is closed
 * as WRITTEN_OFF, so the customer ledger keeps agreeing with 1510
 * (BFL 4 kap. 1 §, 5 kap. 1 §). Money that comes in afterwards is a
 * recovery (RegisterPayment) and the VAT is reported again (7 kap. 43 §).
 *
 * Enligt granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-12, #57).
 *
 * TODO(legal-verify): when a loss counts as confirmed under good accounting
 * practice, and expected losses (6352), which are not handled here.
 */
class WriteOffInvoice
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, Invoice $invoice, CarbonImmutable $date, string $reason): Invoice
    {
        Gate::forUser($user)->authorize('writeOff', $invoice);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Skriv varför fordringen är en konstaterad förlust, till exempel konkurs eller misslyckad indrivning.')]);
        }

        return CurrentCompany::run($invoice->company_id, fn (): Invoice => DB::transaction(function () use ($user, $invoice, $date, $reason): Invoice {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load(['rows.account', 'creditInvoices' => fn ($query) => $query->whereNotNull('number'), 'creditInvoices.rows.account']);
            $company = Company::query()->findOrFail($invoice->company_id);
            $amount = $invoice->remaining()->ore;

            if ($invoice->status !== InvoiceStatus::Issued || $amount <= 0) {
                throw ValidationException::withMessages(['invoice' => __('Bara en obetald faktura kan skrivas bort.')]);
            }

            if ($date->lessThan($invoice->invoice_date)) {
                throw ValidationException::withMessages(['written_off_on' => __('Förlusten kan inte bokföras före fakturadatumet.')]);
            }

            $voucher = null;

            if (InvoiceBooking::bookedAtIssue($invoice, $company->accounting_method)) {
                Account::query()->firstOrCreate(['number' => InvoiceBooking::CUSTOMER_LOSS], [
                    'name' => __('Konstaterade förluster på kundfordringar'),
                    'type' => AccountType::Expense,
                ]);

                $voucher = ($this->bookVoucher)(
                    $user,
                    $company,
                    $date,
                    __('Kundförlust :title, :customer', ['title' => $invoice->title(), 'customer' => $invoice->customer_snapshot['name'] ?? '']).$invoice->bookingNote(),
                    'B',
                    InvoiceBooking::writeOff($invoice, $invoice->rows, $invoice->creditInvoices, $amount),
                    VoucherSource::Invoice,
                    $invoice,
                );
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::WrittenOff,
                'written_off_ore' => $amount,
                'written_off_on' => $date,
                'write_off_voucher_id' => $voucher?->id,
                'write_off_reason' => trim($reason),
            ])->save();

            Audit::record('invoice.written_off', $invoice, array_filter([
                'number' => $invoice->number,
                'amount_ore' => $amount,
                'date' => $date->toDateString(),
                'reason' => trim($reason),
                'voucher' => $voucher?->reference(),
            ]), $company, $user);

            return $invoice;
        }));
    }
}
