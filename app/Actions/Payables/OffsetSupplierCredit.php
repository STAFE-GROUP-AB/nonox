<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Offsets (kvittar) an approved credit note against an approved invoice from
 * the same supplier, for as much as both allow. No money moves. The voucher
 * (series E) moves the amount within 2440 under fakturametoden, so the offset
 * is visible in the ledger; under kontantmetoden it books the invoice's and
 * the credit note's shares of costs and VAT against each other.
 */
class OffsetSupplierCredit
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, SupplierInvoice $credit, SupplierInvoice $invoice, CarbonImmutable $date): int
    {
        Gate::forUser($user)->authorize('pay', $credit);
        Gate::forUser($user)->authorize('pay', $invoice);

        return CurrentCompany::run($credit->company_id, fn (): int => DB::transaction(function () use ($user, $credit, $invoice, $date): int {
            $relations = ['rows.account', 'rows.costCenter', 'rows.project'];
            $credit = SupplierInvoice::query()->with($relations)->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $invoice = SupplierInvoice::query()->with($relations)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $company = Company::query()->findOrFail($credit->company_id);

            if (! $credit->isCredit() || $invoice->isCredit()) {
                throw ValidationException::withMessages(['offset' => __('Välj en kreditnota och en faktura att kvitta den mot.')]);
            }

            if ($credit->supplier_id !== $invoice->supplier_id) {
                throw ValidationException::withMessages(['offset' => __('Kreditnotan och fakturan måste vara från samma leverantör.')]);
            }

            if ($credit->status !== SupplierInvoiceStatus::Approved || $invoice->status !== SupplierInvoiceStatus::Approved) {
                throw ValidationException::withMessages(['offset' => __('Både kreditnotan och fakturan måste vara attesterade och oreglerade.')]);
            }

            // Offsetting in another currency would need the foreign amounts and an exchange difference too (ADR-025).
            if ($credit->isForeign() || $invoice->isForeign()) {
                throw ValidationException::withMessages(['offset' => __('Kreditnotor och fakturor i utländsk valuta kan inte kvittas ännu. Registrera betalningarna var för sig.')]);
            }

            $amount = min(-$credit->remaining()->ore, $invoice->remaining()->ore);
            $payables = Account::query()->where('number', SupplierInvoiceBooking::PAYABLES)->firstOrFail();

            // The two payments meet in the bank line, which cancels out and is left out.
            $rows = array_values(array_filter(
                [
                    ...SupplierInvoiceBooking::payment($invoice, $invoice->rows, $amount, SupplierInvoiceBooking::BANK, ! SupplierInvoiceBooking::bookedAtRegistration($invoice, $company->accounting_method)),
                    ...SupplierInvoiceBooking::payment($credit, $credit->rows, -$amount, SupplierInvoiceBooking::BANK, ! SupplierInvoiceBooking::bookedAtRegistration($credit, $company->accounting_method)),
                ],
                fn (array $row): bool => $row['account'] !== SupplierInvoiceBooking::BANK,
            ));

            $voucher = $this->bookVoucher->rows(
                $user,
                $company,
                $date,
                __('Kvittning :credit mot :invoice, :supplier', ['credit' => $credit->title(), 'invoice' => $invoice->title(), 'supplier' => $invoice->supplierName()]),
                'E',
                $rows,
                VoucherSource::Payment,
                $invoice,
            );

            foreach ([[$invoice, $amount], [$credit, -$amount]] as [$document, $share]) {
                SupplierPayment::create([
                    'supplier_invoice_id' => $document->id,
                    'paid_on' => $date,
                    'amount_ore' => $share,
                    'account_id' => $payables->id,
                    'voucher_id' => $voucher->id,
                    'created_by' => $user->id,
                ]);

                $paid = $document->paid_ore->ore + $share;
                $document->forceFill([
                    'paid_ore' => $paid,
                    'status' => abs($paid) >= abs($document->total_ore->ore) ? SupplierInvoiceStatus::Paid : SupplierInvoiceStatus::Approved,
                ])->save();
            }

            Audit::record('supplier_invoice.offset', $invoice, [
                'credit' => $credit->number,
                'invoice' => $invoice->number,
                'external_ref' => $invoice->external_ref,
                'amount_ore' => $amount,
                'voucher' => $voucher->reference(),
            ], $company, $user);

            return $amount;
        }));
    }
}
