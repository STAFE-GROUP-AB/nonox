<?php

declare(strict_types=1);

namespace App\Actions\Payables;

use App\Actions\Accounting\AttachToVoucher;
use App\Actions\Accounting\BookVoucher;
use App\Actions\Assets\AssetProposals;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\AccountingMethod;
use App\Domain\Payables\SupplierInvoiceBooking;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Company;
use App\Models\NumberSeries;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Registers a draft supplier invoice: it gets the next number, the supplier
 * is kept as it is now, and under fakturametoden it is booked in series D
 * with the invoice file as underlag. Assets proposed on its rows are created
 * in the register in the same transaction. From here on its contents never
 * change; it waits for approval (attest).
 */
class RegisterSupplierInvoice
{
    public function __construct(
        private readonly BookVoucher $bookVoucher,
        private readonly AttachToVoucher $attach,
        private readonly AssetProposals $assetProposals,
    ) {}

    public function __invoke(User $user, SupplierInvoice $invoice): SupplierInvoice
    {
        Gate::forUser($user)->authorize('register', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): SupplierInvoice => DB::transaction(function () use ($user, $invoice): SupplierInvoice {
            /** @var SupplierInvoice $invoice */
            $invoice = SupplierInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load('rows.account', 'rows.costCenter', 'rows.project');
            $company = Company::query()->findOrFail($invoice->company_id);
            $supplier = Supplier::query()->findOrFail($invoice->supplier_id);

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['invoice' => __('Fakturan är redan registrerad.')]);
            }

            if ($invoice->rows->isEmpty()) {
                throw ValidationException::withMessages(['rows' => __('Lägg till minst en rad med konto och belopp.')]);
            }

            $invoice->forceFill([
                'number' => NumberSeries::take(NumberSeries::SUPPLIER_INVOICES),
                'supplier_snapshot' => $supplier->snapshot(),
            ]);

            $voucher = null;

            if ($company->accounting_method !== AccountingMethod::Kontantmetoden) {
                $voucher = $this->bookVoucher->rows(
                    $user,
                    $company,
                    $invoice->invoice_date,
                    __(':title, :supplier', ['title' => $invoice->title(), 'supplier' => $supplier->name]),
                    'D',
                    SupplierInvoiceBooking::register($invoice, $invoice->rows),
                    VoucherSource::SupplierInvoice,
                    $invoice,
                );

                if ($invoice->file_path !== null) {
                    $this->attach->linkStoredFile($user, $voucher, $invoice->file_path, (string) $invoice->file_name, (string) $invoice->file_mime, (int) $invoice->file_size, (string) $invoice->file_sha256);
                }
            }

            $invoice->forceFill([
                'status' => SupplierInvoiceStatus::Registered,
                'voucher_id' => $voucher?->id,
                'registered_at' => now(),
                'registered_by' => $user->id,
            ])->save();

            // Assets proposed on the draft's rows are created now, linked to the invoice and its voucher.
            $this->assetProposals->createForSupplierInvoice($user, $invoice, $voucher);

            Audit::record('supplier_invoice.registered', $invoice, [
                'number' => $invoice->number,
                'supplier' => $supplier->name,
                'total_ore' => $invoice->total_ore->ore,
                'voucher' => $voucher?->reference(),
            ], $company, $user);

            return $invoice;
        }));
    }
}
