<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
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
 */
class RegisterPayment
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, Invoice $invoice, CarbonImmutable $paidOn, Money $amount, int $bankAccount = InvoiceBooking::BANK): InvoicePayment
    {
        Gate::forUser($user)->authorize('registerPayment', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): InvoicePayment => DB::transaction(function () use ($user, $invoice, $paidOn, $amount, $bankAccount): InvoicePayment {
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

            if ($amount->ore > $invoice->remaining()->ore) {
                throw ValidationException::withMessages(['amount' => __('Beloppet är större än det som återstår, :remaining.', ['remaining' => $invoice->remaining()->format()])]);
            }

            $account = Account::query()->where('number', $bankAccount)->first()
                ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $bankAccount])]);

            $voucher = ($this->bookVoucher)(
                $user,
                $company,
                $paidOn,
                __('Inbetalning :invoice, :customer', ['invoice' => $invoice->title(), 'customer' => $invoice->customer_snapshot['name'] ?? '']),
                'C',
                InvoiceBooking::payment($invoice, $invoice->rows, $amount->ore, $bankAccount, $company->accounting_method === AccountingMethod::Kontantmetoden),
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
            ]);

            $paid = $invoice->paid_ore->plus($amount);
            $invoice->forceFill([
                'paid_ore' => $paid->ore,
                'status' => $paid->ore >= $invoice->total_ore->ore ? InvoiceStatus::Paid : InvoiceStatus::Issued,
            ])->save();

            Audit::record('invoice.payment_registered', $invoice, [
                'number' => $invoice->number,
                'amount_ore' => $amount->ore,
                'paid_on' => $paidOn->toDateString(),
                'voucher' => $voucher->reference(),
            ], $company, $user);

            if ($invoice->status === InvoiceStatus::Paid) {
                event(new InvoicePaid($company->id, $invoice->id, $payment->id));
            }

            return $payment;
        }));
    }
}
