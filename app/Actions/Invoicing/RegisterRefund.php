<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
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
 * Registers money paid back to a customer for a credit invoice that left a
 * credit balance (a credit of an invoice the customer had already paid),
 * booked in series C. It is kept as a payment with a negative amount on the
 * credit invoice, so the customer ledger and 1510 stay equal: under
 * fakturametoden 1510 against the bank account; under kontantmetoden the
 * refund's share of the credited revenue and VAT against the bank account
 * (enligt granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-8,
 * #53); Mervärdesskattelag (2023:200) 7 kap. 43-46 §§).
 *
 * A credit in another currency is paid back in that currency, like
 * RegisterPayment: $foreignAmount (hundredths, default what is left) is
 * paid back and $amount is the kronor that left the bank; the difference
 * to the kronor at the booking rate is an exchange gain or loss.
 */
class RegisterRefund
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, Invoice $credit, CarbonImmutable $paidOn, Money $amount, int $bankAccount = InvoiceBooking::BANK, ?int $foreignAmount = null): InvoicePayment
    {
        Gate::forUser($user)->authorize('registerRefund', $credit);

        return CurrentCompany::run($credit->company_id, fn (): InvoicePayment => DB::transaction(function () use ($user, $credit, $paidOn, $amount, $bankAccount, $foreignAmount): InvoicePayment {
            /** @var Invoice $credit */
            $credit = Invoice::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $credit->load('rows.account');
            $company = Company::query()->findOrFail($credit->company_id);
            $left = -$credit->remaining()->ore;

            if (! $credit->isCredit() || $credit->status !== InvoiceStatus::Issued || $left <= 0) {
                throw ValidationException::withMessages(['refund_amount' => __('Kreditfakturan har inget att betala tillbaka.')]);
            }

            if ($amount->ore <= 0) {
                throw ValidationException::withMessages(['refund_amount' => __('Skriv ett belopp större än noll.')]);
            }

            $foreignLeft = $credit->foreignRemaining() === null ? null : -$credit->foreignRemaining();
            $settled = $amount->ore;

            if ($foreignLeft !== null) {
                $foreignAmount ??= $foreignLeft;

                if ($foreignAmount <= 0 || $foreignAmount > $foreignLeft) {
                    throw ValidationException::withMessages(['refund_foreign_amount' => __('Beloppet ska vara mellan 0,01 och det som återstår, :left.', ['left' => $credit->formatForeign($foreignLeft)])]);
                }

                $settled = $foreignAmount === $foreignLeft
                    ? $left
                    : min(-InvoiceBooking::settledShare($credit, -$foreignAmount), $left - 1);
            } elseif ($foreignAmount !== null) {
                throw ValidationException::withMessages(['refund_foreign_amount' => __('Fakturan är i kronor.')]);
            } elseif ($amount->ore > $left) {
                throw ValidationException::withMessages(['refund_amount' => __('Beloppet är större än det som återstår, :remaining.', ['remaining' => Money::ofOre($left)->format()])]);
            }

            $account = Account::query()->where('number', $bankAccount)->first()
                ?? throw ValidationException::withMessages(['refund_amount' => __('Konto :number finns inte i kontoplanen.', ['number' => $bankAccount])]);

            // As for a payment: kronor received (here negative, paid out) minus kronor settled; positive a gain.
            $difference = -$amount->ore + $settled;

            if ($difference !== 0) {
                RegisterPayment::ensureExchangeAccount($difference > 0 ? InvoiceBooking::EXCHANGE_GAIN : InvoiceBooking::EXCHANGE_LOSS, $user, $company);
            }

            $voucher = ($this->bookVoucher)(
                $user,
                $company,
                $paidOn,
                __('Återbetalning :invoice, :customer', ['invoice' => $credit->title(), 'customer' => $credit->customer_snapshot['name'] ?? '']),
                'C',
                InvoiceBooking::payment($credit, $credit->rows, -$settled, $bankAccount, $company->accounting_method === AccountingMethod::Kontantmetoden, -$amount->ore),
                VoucherSource::Payment,
                $credit,
            );

            $payment = InvoicePayment::create([
                'invoice_id' => $credit->id,
                'paid_on' => $paidOn,
                'amount_ore' => -$amount->ore,
                'account_id' => $account->id,
                'voucher_id' => $voucher->id,
                'created_by' => $user->id,
                'foreign_amount_minor' => $foreignLeft === null ? null : -$foreignAmount,
                'exchange_difference_ore' => $difference,
            ]);

            $paid = $credit->paid_ore->ore - $settled;
            $foreignPaid = $credit->foreign_paid_minor - ($foreignLeft === null ? 0 : (int) $foreignAmount);
            $settledAll = $foreignLeft === null ? $paid <= $credit->total_ore->ore : $foreignPaid <= (int) $credit->foreign_total_minor;

            $credit->forceFill([
                'paid_ore' => $paid,
                'foreign_paid_minor' => $foreignPaid,
                'status' => $settledAll ? InvoiceStatus::Paid : InvoiceStatus::Issued,
            ])->save();

            Audit::record('invoice.refund_registered', $credit, array_filter([
                'number' => $credit->number,
                'amount_ore' => $amount->ore,
                'paid_on' => $paidOn->toDateString(),
                'voucher' => $voucher->reference(),
                'currency' => $foreignLeft === null ? null : $credit->invoice_currency,
                'foreign_amount_minor' => $foreignLeft === null ? null : $foreignAmount,
                'settled_ore' => $foreignLeft === null ? null : $settled,
                'exchange_difference_ore' => $foreignLeft === null ? null : $difference,
            ], fn (mixed $value): bool => $value !== null), $company, $user);

            return $payment;
        }));
    }
}
