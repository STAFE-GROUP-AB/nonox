<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\AccountingMethod;
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
 */
class PaySupplierInvoice
{
    public function __construct(
        private readonly BookVoucher $bookVoucher,
        private readonly AttachToVoucher $attach,
    ) {}

    public function __invoke(User $user, SupplierInvoice $invoice, CarbonImmutable $paidOn, Money $amount, int $bankAccount = SupplierInvoiceBooking::BANK): SupplierPayment
    {
        Gate::forUser($user)->authorize('pay', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): SupplierPayment => DB::transaction(function () use ($user, $invoice, $paidOn, $amount, $bankAccount): SupplierPayment {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load('rows.account', 'rows.costCenter', 'rows.project');
            $company = Company::query()->findOrFail($invoice->company_id);
            $cashMethod = $company->accounting_method === AccountingMethod::Kontantmetoden;

            if ($invoice->status !== SupplierInvoiceStatus::Approved) {
                throw ValidationException::withMessages(['amount' => __('Fakturan är inte attesterad och obetald.')]);
            }

            if ($amount->ore <= 0) {
                throw ValidationException::withMessages(['amount' => __('Skriv ett belopp större än noll.')]);
            }

            if ($amount->ore > $invoice->remaining()->ore) {
                throw ValidationException::withMessages(['amount' => __('Beloppet är större än det som återstår, :remaining.', ['remaining' => $invoice->remaining()->format()])]);
            }

            $account = Account::query()->where('number', $bankAccount)->first()
                ?? throw ValidationException::withMessages(['account' => __('Konto :number finns inte i kontoplanen.', ['number' => $bankAccount])]);

            $voucher = $this->bookVoucher->rows(
                $user,
                $company,
                $paidOn,
                __('Betalning :title, :supplier', ['title' => $invoice->title(), 'supplier' => $invoice->supplierName()]),
                'E',
                SupplierInvoiceBooking::payment($invoice, $invoice->rows, $amount->ore, $bankAccount, $cashMethod),
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
            ]);

            $paid = $invoice->paid_ore->plus($amount);
            $invoice->forceFill([
                'paid_ore' => $paid->ore,
                'status' => $paid->ore >= $invoice->total_ore->ore ? SupplierInvoiceStatus::Paid : SupplierInvoiceStatus::Approved,
            ])->save();

            Audit::record('supplier_invoice.paid', $invoice, [
                'number' => $invoice->number,
                'amount_ore' => $amount->ore,
                'paid_on' => $paidOn->toDateString(),
                'voucher' => $voucher->reference(),
            ], $company, $user);

            return $payment;
        }));
    }
}
