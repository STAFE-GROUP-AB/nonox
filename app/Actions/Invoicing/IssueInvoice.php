<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Ocr;
use App\Domain\Invoicing\TaxReduction;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NumberSeries;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Makes a draft a real invoice: the next invoice number (gapless, shared by
 * invoices and credit invoices), an OCR reference, the customer as it is
 * now, and under fakturametoden a voucher in series B. After this the
 * invoice never changes.
 *
 * A credit invoice settles the invoice it credits: both are closed, and the
 * receivable nets to zero.
 */
class IssueInvoice
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, Invoice $invoice): Invoice
    {
        Gate::forUser($user)->authorize('issue', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): Invoice => DB::transaction(function () use ($user, $invoice): Invoice {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->load(['rows.account']);
            $company = Company::query()->findOrFail($invoice->company_id);
            $customer = Customer::query()->findOrFail($invoice->customer_id);

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['invoice' => __('Fakturan är redan skickad.')]);
            }

            if ($invoice->rows->isEmpty() || $invoice->total_ore->isZero()) {
                throw ValidationException::withMessages(['invoice' => __('Fakturan saknar belopp.')]);
            }

            $original = $invoice->isCredit() ? Invoice::query()->whereKey($invoice->credits_invoice_id)->lockForUpdate()->first() : null;

            if ($invoice->isCredit() && ($original === null || $original->status !== InvoiceStatus::Issued || ! $original->paid_ore->isZero())) {
                throw ValidationException::withMessages(['invoice' => __('Bara en obetald faktura kan krediteras.')]);
            }

            if ($invoice->reverse_charge) {
                $this->ensureReverseCharge($company, $customer);
            }

            if ($invoice->tax_reduction !== null) {
                $this->ensureTaxReduction($company, $invoice);
            }

            $number = NumberSeries::take(NumberSeries::INVOICES);

            $invoice->forceFill([
                'number' => $number,
                'status' => $invoice->isCredit() ? InvoiceStatus::Paid : InvoiceStatus::Issued,
                'ocr' => Ocr::forInvoiceNumber($number),
                'customer_snapshot' => $customer->snapshot(),
                'issued_at' => CarbonImmutable::now(),
                'issued_by' => $user->id,
                'paid_ore' => $invoice->isCredit() ? $invoice->total_ore->ore : 0,
            ]);

            if ($company->accounting_method === AccountingMethod::Fakturametoden) {
                $voucher = ($this->bookVoucher)(
                    $user,
                    $company,
                    $invoice->invoice_date,
                    "{$invoice->title()}, {$customer->name}",
                    'B',
                    InvoiceBooking::issue($invoice, $invoice->rows),
                    VoucherSource::Invoice,
                    $invoice,
                );

                $invoice->voucher_id = $voucher->id;
            }

            $invoice->save();

            $original?->forceFill(['status' => InvoiceStatus::Credited, 'paid_ore' => $original->total_ore->ore])->save();

            Audit::record('invoice.issued', $invoice, [
                'number' => $number,
                'kind' => $invoice->kind,
                'customer' => $customer->name,
                'total_ore' => $invoice->total_ore->ore,
                'credits' => $original?->number,
            ], $company, $user);

            return $invoice;
        }));
    }

    /**
     * An invoice under omvänd betalningsskyldighet must show both parties'
     * VAT numbers; the sale is booked on 3231, which is added if missing.
     *
     * TODO(legal-verify): the invoice requirements for reverse charge in
     * construction (ML 17 kap.) and the wording on the invoice.
     */
    private function ensureReverseCharge(Company $company, Customer $customer): void
    {
        if (blank($company->vat_number)) {
            throw ValidationException::withMessages(['invoice' => __('Fyll i företagets momsregistreringsnummer under Företagsuppgifter. Det ska stå på fakturor med omvänd betalningsskyldighet.')]);
        }

        if (blank($customer->vat_number)) {
            throw ValidationException::withMessages(['invoice' => __('Kunden saknar momsregistreringsnummer. Det ska stå på fakturor med omvänd betalningsskyldighet.')]);
        }

        Account::query()->firstOrCreate(['number' => InvoiceBooking::REVERSE_CHARGE_CONSTRUCTION], [
            'name' => __('Försäljning inom byggtjänster, omvänd skattskyldighet'),
            'type' => AccountType::Revenue,
        ]);
    }

    /**
     * ROT and RUT need fakturametoden here, and ROT the property the work
     * was done on; the part Skatteverket pays is booked on 1513.
     *
     * TODO(legal-verify): ROT and RUT under kontantmetoden, and the details
     * the invoice and the claim must carry.
     */
    private function ensureTaxReduction(Company $company, Invoice $invoice): void
    {
        if ($company->accounting_method !== AccountingMethod::Fakturametoden) {
            throw ValidationException::withMessages(['invoice' => __('ROT- och RUT-avdrag fungerar än så länge bara med fakturametoden.')]);
        }

        if ($invoice->tax_reduction === TaxReduction::ROT && blank($invoice->property_designation)) {
            throw ValidationException::withMessages(['invoice' => __('Fyll i fastighetsbeteckningen, eller bostadsrättsföreningens organisationsnummer och lägenhetsnummer, för ROT-avdraget.')]);
        }

        Account::query()->firstOrCreate(['number' => TaxReduction::RECEIVABLE], [
            'name' => __('Kundfordringar – delad faktura'),
            'type' => AccountType::Asset,
        ]);
    }
}
