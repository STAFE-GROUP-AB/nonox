<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Events\InvoicePaid;
use App\Models\Account;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
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
                __('Inbetalning :invoice, :customer', ['invoice' => $invoice->title(), 'customer' => $invoice->customer_snapshot['name'] ?? '']),
                'C',
                InvoiceBooking::payment($invoice, $invoice->rows, $settled, $bankAccount, $company->accounting_method === AccountingMethod::Kontantmetoden, $amount->ore),
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

            Audit::record('invoice.payment_registered', $invoice, array_filter([
                'number' => $invoice->number,
                'amount_ore' => $amount->ore,
                'paid_on' => $paidOn->toDateString(),
                'voucher' => $voucher->reference(),
                'currency' => $foreignRemaining === null ? null : $invoice->invoice_currency,
                'foreign_amount_minor' => $foreignRemaining === null ? null : $foreignAmount,
                'settled_ore' => $foreignRemaining === null ? null : $settled,
                'exchange_difference_ore' => $foreignRemaining === null ? null : $difference,
            ], fn (mixed $value): bool => $value !== null), $company, $user);

            if ($invoice->status === InvoiceStatus::Paid) {
                event(new InvoicePaid($company->id, $invoice->id, $payment->id));
            }

            return $payment;
        }));
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
