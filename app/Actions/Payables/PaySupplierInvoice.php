<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Registers money paid to a supplier for an approved invoice, booked in
 * series E. An invoice is paid when nothing remains; a part payment leaves
 * it open. Under kontantmetoden the payment books the costs and VAT, with
 * the invoice file as underlag on the first payment.
 *
 * An invoice in another currency is paid in that currency: $foreignAmount
 * (hundredths, default what is left) is paid down and $amount is the kronor
 * the bank actually took. The payment settles the invoice's kronor share of
 * that foreign amount at the booking rate, and the difference to $amount is
 * booked on 3960 or 7960 (ADR-025).
 */
class PaySupplierInvoice
{
    public function __construct(
        private readonly BookVoucher $bookVoucher,
        private readonly AttachToVoucher $attach,
    ) {}

    public function __invoke(User $user, SupplierInvoice $invoice, CarbonImmutable $paidOn, Money $amount, int $bankAccount = SupplierInvoiceBooking::BANK, ?int $foreignAmount = null): SupplierPayment
    {
        Gate::forUser($user)->authorize('pay', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): SupplierPayment => DB::transaction(function () use ($user, $invoice, $paidOn, $amount, $bankAccount, $foreignAmount): SupplierPayment {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load('rows.account', 'rows.costCenter', 'rows.project');
            $company = Company::query()->findOrFail($invoice->company_id);
            $cashMethod = ! SupplierInvoiceBooking::bookedAtRegistration($invoice, $company->accounting_method);

            if ($invoice->status !== SupplierInvoiceStatus::Approved) {
                throw ValidationException::withMessages(['amount' => __('Fakturan är inte attesterad och obetald.')]);
            }

            // A credit note is settled by money coming back, a negative payment.
            $sign = $invoice->isCredit() ? -1 : 1;

            if ($amount->ore * $sign <= 0) {
                throw ValidationException::withMessages(['amount' => __('Skriv ett belopp större än noll.')]);
            }

            $foreignRemaining = $invoice->foreignRemaining();
            $settled = $amount->ore;

            if ($foreignRemaining !== null) {
                $foreignAmount ??= $foreignRemaining;

                if ($foreignAmount * $sign <= 0) {
                    throw ValidationException::withMessages(['foreign_amount' => __('Skriv ett belopp i :currency större än noll.', ['currency' => $invoice->invoice_currency])]);
                }

                if ($foreignAmount * $sign > $foreignRemaining * $sign) {
                    throw ValidationException::withMessages(['foreign_amount' => __('Beloppet är större än det som återstår, :remaining.', ['remaining' => $invoice->formatForeign(abs($foreignRemaining))])]);
                }

                $settled = SupplierInvoiceBooking::settledShare($invoice, $foreignAmount);
            } elseif ($foreignAmount !== null) {
                throw ValidationException::withMessages(['foreign_amount' => __('Fakturan är i kronor.')]);
            } elseif ($amount->ore * $sign > $invoice->remaining()->ore * $sign) {
                throw ValidationException::withMessages(['amount' => __('Beloppet är större än det som återstår, :remaining.', ['remaining' => Money::ofOre(abs($invoice->remaining()->ore))->format()])]);
            }

            $account = Account::query()->where('number', $bankAccount)->first()
                ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $bankAccount])]);

            $difference = $amount->ore - $settled;

            if ($difference !== 0) {
                $this->ensureExchangeAccount($difference > 0 ? SupplierInvoiceBooking::EXCHANGE_LOSS : SupplierInvoiceBooking::EXCHANGE_GAIN, $user, $company);
            }

            $voucher = $this->bookVoucher->rows(
                $user,
                $company,
                $paidOn,
                $invoice->isCredit()
                    ? __('Återbetalning :title, :supplier', ['title' => $invoice->title(), 'supplier' => $invoice->supplierName()])
                    : __('Betalning :title, :supplier', ['title' => $invoice->title(), 'supplier' => $invoice->supplierName()]),
                'E',
                SupplierInvoiceBooking::payment($invoice, $invoice->rows, $settled, $bankAccount, $cashMethod, $amount->ore),
                VoucherSource::Payment,
                $invoice,
            );

            if ($cashMethod && $invoice->paid_ore->isZero() && $invoice->file_path !== null) {
                $this->attach->linkStoredFile($user, $voucher, $invoice->file_path, (string) $invoice->file_name, (string) $invoice->file_mime, (int) $invoice->file_size, (string) $invoice->file_sha256);
            }

            $payment = SupplierPayment::create([
                'supplier_invoice_id' => $invoice->id,
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
            $invoice->forceFill([
                'paid_ore' => $paid->ore,
                'foreign_paid_minor' => $foreignPaid,
                'status' => abs($paid->ore) >= abs($invoice->total_ore->ore) ? SupplierInvoiceStatus::Paid : SupplierInvoiceStatus::Approved,
            ])->save();

            Audit::record('supplier_invoice.paid', $invoice, array_filter([
                'number' => $invoice->number,
                'amount_ore' => $amount->ore,
                'paid_on' => $paidOn->toDateString(),
                'voucher' => $voucher->reference(),
                'currency' => $foreignRemaining === null ? null : $invoice->invoice_currency,
                'foreign_amount_minor' => $foreignRemaining === null ? null : $foreignAmount,
                'settled_ore' => $foreignRemaining === null ? null : $settled,
                'exchange_difference_ore' => $foreignRemaining === null ? null : $difference,
            ], fn (mixed $value): bool => $value !== null), $company, $user);

            return $payment;
        }));
    }

    /**
     * Adds 3960 or 7960 from BAS when the company's chart lacks it. A
     * deactivated one is left as it is, and BookVoucher refuses it.
     */
    private function ensureExchangeAccount(int $number, User $user, Company $company): void
    {
        $account = Account::query()->where('number', $number)->first();

        if ($account instanceof Account) {
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
