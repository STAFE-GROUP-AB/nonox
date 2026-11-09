<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\TaxReduction;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Events\InvoicePaid;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Registers money received for an invoice, booked in series C. An invoice
 * is paid when nothing remains; a part payment leaves it open.
 *
 * An invoice in another currency is paid in that currency: $foreignAmount
 * (hundredths, default what is left) is paid down and $amount is the kronor
 * that actually reached the bank. The payment settles the invoice's kronor
 * share of that foreign amount at the booking rate, and the difference to
 * $amount is booked on 3960 (gain) or 7960 (loss) (ADR-025). A payment of
 * more than what remains, in kronor or in the currency, is refused.
 */
class RegisterPayment
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, Invoice $invoice, CarbonImmutable $paidOn, Money $amount, int $bankAccount = InvoiceBooking::BANK, ?int $foreignAmount = null): InvoicePayment
    {
        Gate::forUser($user)->authorize('registerPayment', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): InvoicePayment => DB::transaction(function () use ($user, $invoice, $paidOn, $amount, $bankAccount, $foreignAmount): InvoicePayment {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load('rows.account');
            $company = Company::query()->findOrFail($invoice->company_id);

            if ($invoice->status === InvoiceStatus::WrittenOff) {
                return $this->recover($user, $company, $invoice, $paidOn, $amount, $bankAccount);
            }

            if ($invoice->status !== InvoiceStatus::Issued) {
                throw ValidationException::withMessages(['amount' => __('Fakturan är inte obetald.')]);
            }

            if ($amount->ore <= 0) {
                throw ValidationException::withMessages(['amount' => __('Skriv ett belopp större än noll.')]);
            }

            $foreignRemaining = $invoice->foreignRemaining();
            $settled = $amount->ore;

            if ($foreignRemaining !== null) {
                $foreignAmount ??= $foreignRemaining;

                if ($foreignAmount <= 0) {
                    throw ValidationException::withMessages(['foreign_amount' => __('Skriv ett belopp i :currency större än noll.', ['currency' => $invoice->invoice_currency])]);
                }

                if ($foreignAmount > $foreignRemaining) {
                    throw ValidationException::withMessages(['foreign_amount' => __('Beloppet är större än det som återstår, :remaining.', ['remaining' => $invoice->formatForeign($foreignRemaining)])]);
                }

                // A part payment never settles all the kronor; the payment that clears the currency does.
                $settled = $foreignAmount === $foreignRemaining
                    ? $invoice->remaining()->ore
                    : min(InvoiceBooking::settledShare($invoice, $foreignAmount), $invoice->remaining()->ore - 1);
            } elseif ($foreignAmount !== null) {
                throw ValidationException::withMessages(['foreign_amount' => __('Fakturan är i kronor.')]);
            } elseif ($amount->ore > $invoice->remaining()->ore) {
                throw ValidationException::withMessages(['amount' => __('Beloppet är större än det som återstår, :remaining.', ['remaining' => $invoice->remaining()->format()])]);
            }

            $account = Account::query()->where('number', $bankAccount)->first()
                ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $bankAccount])]);

            $difference = $amount->ore - $settled;

            if ($difference !== 0) {
                self::ensureExchangeAccount($difference > 0 ? InvoiceBooking::EXCHANGE_GAIN : InvoiceBooking::EXCHANGE_LOSS, $user, $company);
            }

            $voucher = ($this->bookVoucher)(
                $user,
                $company,
                $paidOn,
                __('Inbetalning :invoice, :customer', ['invoice' => $invoice->title(), 'customer' => $invoice->customer_snapshot['name'] ?? '']).$invoice->bookingNote(),
                'C',
                InvoiceBooking::payment($invoice, $invoice->rows, $settled, $bankAccount, ! InvoiceBooking::bookedAtIssue($invoice, $company->accounting_method), $amount->ore),
                VoucherSource::Payment,
                $invoice,
            );

            $payment = InvoicePayment::create([
                'invoice_id' => $invoice->id,
                'paid_on' => $paidOn,
                'amount_ore' => $amount->ore,
                'account_id' => $account->id,
                'voucher_id' => $voucher->id,
                'created_by' => $user->id,
                'foreign_amount_minor' => $foreignRemaining === null ? null : $foreignAmount,
                'exchange_difference_ore' => $difference,
            ]);

            $paid = $invoice->paid_ore->plus(Money::ofOre($settled));
            $foreignPaid = $invoice->foreign_paid_minor + ($foreignRemaining === null ? 0 : (int) $foreignAmount);
            $settledAll = $foreignRemaining === null ? $paid->ore >= $invoice->total_ore->ore : $foreignPaid >= (int) $invoice->foreign_total_minor;
            $invoice->forceFill([
                'paid_ore' => $paid->ore,
                'foreign_paid_minor' => $foreignPaid,
                'status' => $settledAll ? InvoiceStatus::Paid : InvoiceStatus::Issued,
            ])->save();

            // ROT/RUT: the year the buyer actually paid decides the share and the ceiling (#56).
            $recheck = $invoice->status === InvoiceStatus::Paid ? TaxReduction::recheck($invoice, $paidOn) : null;

            Audit::record('invoice.payment_registered', $invoice, array_filter([
                'number' => $invoice->number,
                'external_ref' => $invoice->external_ref,
                'amount_ore' => $amount->ore,
                'paid_on' => $paidOn->toDateString(),
                'voucher' => $voucher->reference(),
                'currency' => $foreignRemaining === null ? null : $invoice->invoice_currency,
                'foreign_amount_minor' => $foreignRemaining === null ? null : $foreignAmount,
                'settled_ore' => $foreignRemaining === null ? null : $settled,
                'exchange_difference_ore' => $foreignRemaining === null ? null : $difference,
                'tax_reduction_recheck' => $recheck,
            ], fn (mixed $value): bool => $value !== null), $company, $user);

            if ($invoice->status === InvoiceStatus::Paid) {
                event(new InvoicePaid($company->id, $invoice->id, $payment->id));
            }

            return $payment;
        }));
    }

    /**
     * Money for an invoice written off as a customer loss (#57): the VAT
     * reduced at the write-off is reported again, in proportion (ML 2023:200
     * 7 kap. 43 §), and the rest reverses the loss on 6351; the recovery
     * that takes the last of the written-off amount books what is left of
     * each line. Under kontantmetoden, when nothing was booked, it books its
     * share of the revenue and VAT like any payment. The receivable is
     * already closed, so 1510 is not touched (enligt granskning 2026-11-02,
     * juristgranskning återstår (AUD-FAKT-12, #57)).
     */
    private function recover(User $user, Company $company, Invoice $invoice, CarbonImmutable $paidOn, Money $amount, int $bankAccount): InvoicePayment
    {
        $left = $invoice->written_off_ore->ore - $invoice->recovered_ore->ore;

        if ($amount->ore <= 0) {
            throw ValidationException::withMessages(['amount' => __('Skriv ett belopp större än noll.')]);
        }

        if ($amount->ore > $left) {
            throw ValidationException::withMessages(['amount' => __('Beloppet är större än det som skrevs bort och ännu inte har kommit in, :left.', ['left' => Money::ofOre($left)->format()])]);
        }

        $account = Account::query()->where('number', $bankAccount)->first()
            ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $bankAccount])]);

        $loss = [];

        if ($invoice->write_off_voucher_id !== null) {
            foreach (Voucher::query()->with('rows.account')->findOrFail($invoice->write_off_voucher_id)->rows as $row) {
                $number = $row->account instanceof Account ? $row->account->number : 0;

                if ($number !== InvoiceBooking::RECEIVABLES) {
                    // Negated: the recovery credits what the write-off debited.
                    $loss[$number] = ($loss[$number] ?? 0) - ($row->debit_ore->ore - $row->credit_ore->ore);
                }
            }
        } else {
            $loss = InvoiceBooking::revenue($invoice, $invoice->rows);
        }

        $base = $invoice->write_off_voucher_id !== null ? $invoice->written_off_ore->ore : $invoice->total_ore->ore;
        $lines = InvoiceBooking::cashShare($loss, $amount->ore, $base);

        if ($amount->ore === $left && $invoice->write_off_voucher_id !== null) {
            // The last recovery takes the rest of each line.
            $lines = $loss;
            $earlier = InvoicePayment::query()->where('invoice_id', $invoice->id)->where('recovery', true)->pluck('amount_ore');

            foreach ($earlier as $paid) {
                foreach (InvoiceBooking::cashShare($loss, $paid instanceof Money ? $paid->ore : (int) $paid, $base) as $number => $share) {
                    $lines[$number] -= $share;
                }
            }
        }

        $voucher = ($this->bookVoucher)(
            $user,
            $company,
            $paidOn,
            __('Inbetalning efter kundförlust :invoice, :customer', ['invoice' => $invoice->title(), 'customer' => $invoice->customer_snapshot['name'] ?? '']).$invoice->bookingNote(),
            'C',
            [$bankAccount => $amount->ore] + array_filter($lines),
            VoucherSource::Payment,
            $invoice,
        );

        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'paid_on' => $paidOn,
            'amount_ore' => $amount->ore,
            'account_id' => $account->id,
            'voucher_id' => $voucher->id,
            'created_by' => $user->id,
            'exchange_difference_ore' => 0,
            'recovery' => true,
        ]);

        $invoice->forceFill(['recovered_ore' => $invoice->recovered_ore->ore + $amount->ore])->save();

        Audit::record('invoice.loss_recovered', $invoice, [
            'number' => $invoice->number,
            'amount_ore' => $amount->ore,
            'paid_on' => $paidOn->toDateString(),
            'voucher' => $voucher->reference(),
        ], $company, $user);

        return $payment;
    }

    /**
     * Adds 3960 or 7960 from BAS when the company's chart lacks it, as for
     * supplier invoices. A deactivated one is left as it is, and BookVoucher
     * refuses it.
     */
    public static function ensureExchangeAccount(int $number, User $user, Company $company): void
    {
        if (Account::query()->where('number', $number)->exists()) {
            return;
        }

        $account = Account::create([
            'number' => $number,
            'name' => SupplierInvoiceBooking::EXCHANGE_ACCOUNT_NAMES[$number],
            'type' => AccountType::forNumber($number),
        ]);

        Audit::record('account.created', $account, ['number' => $number, 'name' => $account->name, 'reason' => 'exchange_difference'], $company, $user);
    }
}
