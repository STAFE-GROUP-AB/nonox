<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\ForeignSale;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Ocr;
use App\Domain\Invoicing\TaxReduction;
use App\Domain\Vat\OssRates;
use App\Domain\Vat\OssThreshold;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NumberSeries;
use App\Models\User;
use App\Models\VatNumberCheck;
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
 * A credit invoice settles the invoice it credits by its own amount
 * (Mervärdesskattelag (2023:200) 17 kap. 22-23 §§: a credit note changes
 * the original by what it credits). A partial credit leaves the rest of
 * the original open to be paid; the original is closed only when nothing
 * remains. A credit larger than what remains is refused, so the customer
 * ledger always agrees with 1510 (BFL 4 kap. 1 §, 5 kap. 1 §) (enligt
 * granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-13, #47)).
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

            if (! $invoice->isCredit()) {
                $customer->ensureNotRestricted('invoice');
            }

            if ($invoice->rows->isEmpty() || $invoice->total_ore->isZero()) {
                throw ValidationException::withMessages(['invoice' => __('Fakturan saknar belopp.')]);
            }

            $original = $invoice->isCredit() ? Invoice::query()->whereKey($invoice->credits_invoice_id)->lockForUpdate()->first() : null;

            if ($invoice->isCredit()) {
                $this->ensureCreditFits($invoice, $original);
            }

            if ($invoice->reverse_charge) {
                $this->ensureReverseCharge($company, $customer);
            }

            if ($invoice->tax_reduction !== null) {
                $this->ensureTaxReduction($company, $invoice);
            }

            if ($invoice->foreign_sale !== null) {
                $this->ensureForeignSale($company, $customer, $invoice->foreign_sale);
            }

            // Other invoices issued since the draft was saved may have moved the company over the OSS threshold.
            if ($invoice->foreign_sale === ForeignSale::EuConsumerOss && ! $invoice->isCredit()
                && OssThreshold::check($company, $invoice->invoice_date, $invoice->net_ore->ore, $invoice->id)['swedish_vat'] !== $invoice->swedish_vat_under_threshold) {
                throw ValidationException::withMessages(['invoice' => __('Gränsen för försäljning till privatpersoner i andra EU-länder har passerats eller ändrats sedan utkastet sparades. Spara utkastet igen så att rätt moms används.')]);
            }

            $number = NumberSeries::take(NumberSeries::INVOICES);

            $invoice->forceFill([
                'number' => $number,
                'status' => $invoice->isCredit() ? InvoiceStatus::Paid : InvoiceStatus::Issued,
                'ocr' => Ocr::forInvoiceNumber($number),
                'customer_snapshot' => $customer->snapshot(),
                'issued_at' => CarbonImmutable::now(),
                'issued_by' => $user->id,
                'paid_ore' => 0,
                'foreign_paid_minor' => 0,
            ]);

            if ($company->accounting_method === AccountingMethod::Fakturametoden) {
                $voucher = ($this->bookVoucher)(
                    $user,
                    $company,
                    $invoice->invoice_date,
                    $invoice->isForeign()
                        ? __(':title, :customer, :amount till kurs :rate', ['title' => $invoice->title(), 'customer' => $customer->name, 'amount' => $invoice->formatForeign((int) $invoice->foreign_total_minor), 'rate' => $invoice->bookingRate()?->format()])
                        : "{$invoice->title()}, {$customer->name}",
                    'B',
                    InvoiceBooking::issue($invoice, $invoice->rows),
                    VoucherSource::Invoice,
                    $invoice,
                );

                $invoice->voucher_id = $voucher->id;
            }

            $invoice->save();

            if ($original instanceof Invoice) {
                $this->settle($invoice, $original);
            }

            Audit::record('invoice.issued', $invoice, [
                'number' => $number,
                'kind' => $invoice->kind,
                'customer' => $customer->name,
                'total_ore' => $invoice->total_ore->ore,
                'credits' => $original?->number,
            ] + ($original instanceof Invoice ? ['credit_settled_ore' => -$invoice->paid_ore->ore] : []) + ($invoice->isForeign() ? [
                'currency' => $invoice->invoice_currency,
                'foreign_total_minor' => $invoice->foreign_total_minor,
                'exchange_rate_micros' => $invoice->exchange_rate_micros,
                'exchange_rate_date' => $invoice->exchange_rate_date?->toDateString(),
                'exchange_rate_source' => $invoice->exchange_rate_source,
            ] : []), $company, $user);

            return $invoice;
        }));
    }

    /**
     * A credit invoice credits an issued invoice that is not paid by the
     * customer, by an amount below zero and no larger than what remains.
     */
    private function ensureCreditFits(Invoice $credit, ?Invoice $original): void
    {
        if ($original === null || $original->isCredit() || $original->status !== InvoiceStatus::Issued || $original->paid_ore->ore !== $original->credited_ore->ore) {
            throw ValidationException::withMessages(['invoice' => __('Bara en obetald faktura kan krediteras.')]);
        }

        if ($credit->total_ore->ore >= 0) {
            throw ValidationException::withMessages(['invoice' => __('En kreditfaktura ska ha ett belopp under noll.')]);
        }

        $foreignRemaining = $original->foreignRemaining();

        if (-$credit->total_ore->ore > $original->remaining()->ore || ($foreignRemaining !== null && -(int) $credit->foreign_total_minor > $foreignRemaining)) {
            throw ValidationException::withMessages(['invoice' => __('Kreditfakturan är större än det som återstår av :title, :remaining.', ['title' => $original->title(), 'remaining' => $original->formatRemaining()])]);
        }
    }

    /**
     * The credit settles the original by its own amount, in kronor and in
     * the invoice's currency. The original is closed when nothing remains:
     * credited when only credits settled it, paid when payments did too.
     */
    private function settle(Invoice $credit, Invoice $original): void
    {
        $foreignRemaining = $original->foreignRemaining();
        $foreign = $foreignRemaining === null ? 0 : min(-(int) $credit->foreign_total_minor, $foreignRemaining);
        // When the credit clears the currency, it clears the kronor too, so no öre is left behind on 1510.
        $kronor = $foreignRemaining !== null && $foreign === $foreignRemaining
            ? $original->remaining()->ore
            : min(-$credit->total_ore->ore, $original->remaining()->ore);

        $paid = $original->paid_ore->ore + $kronor;
        $credited = $original->credited_ore->ore + $kronor;
        $closed = $foreignRemaining === null ? $paid >= $original->total_ore->ore : $original->foreign_paid_minor + $foreign >= (int) $original->foreign_total_minor;

        $original->forceFill([
            'paid_ore' => $paid,
            'credited_ore' => $credited,
            'foreign_paid_minor' => $original->foreign_paid_minor + $foreign,
            'status' => match (true) {
                ! $closed => InvoiceStatus::Issued,
                $credited >= $original->total_ore->ore => InvoiceStatus::Credited,
                default => InvoiceStatus::Paid,
            },
        ])->save();

        $creditClosed = $foreignRemaining === null ? $kronor >= -$credit->total_ore->ore : $foreign >= -(int) $credit->foreign_total_minor;

        $credit->forceFill([
            'paid_ore' => -$kronor,
            'foreign_paid_minor' => -$foreign,
            'status' => $creditClosed ? InvoiceStatus::Paid : InvoiceStatus::Issued,
        ])->save();
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
     * A sale to a business in another EU country needs both parties' VAT
     * numbers, the buyer's from that country; an export needs a buyer
     * outside the EU. The revenue account is added if missing.
     *
     * TODO(legal-verify): whether a valid VAT number must be checked in VIES
     * before issuing, and the evidence required that goods left Sweden.
     */
    private function ensureForeignSale(Company $company, Customer $customer, ForeignSale $sale): void
    {
        $country = strtoupper($customer->country);

        if ($sale->isEu() && ! ForeignSale::isEuCountry($country)) {
            throw ValidationException::withMessages(['invoice' => __('Kunden ska ha ett annat EU-land än Sverige som land för försäljning inom EU.')]);
        }

        if ($sale === ForeignSale::EuConsumerOss) {
            Account::query()->firstOrCreate(['number' => OssRates::VAT_ACCOUNT], [
                'name' => __('Utgående moms, OSS'),
                'type' => AccountType::LiabilityEquity,
            ]);
        }

        if ($sale->requiresBuyerVatNumber()) {

            if (blank($company->vat_number)) {
                throw ValidationException::withMessages(['invoice' => __('Fyll i företagets momsregistreringsnummer under Företagsuppgifter. Det ska stå på fakturor till andra EU-länder.')]);
            }

            $prefix = $country === 'GR' ? 'EL' : $country;

            if (! str_starts_with(strtoupper((string) $customer->vat_number), $prefix)) {
                throw ValidationException::withMessages(['invoice' => __('Kunden behöver ett momsregistreringsnummer från :country för försäljning utan moms inom EU.', ['country' => $country])]);
            }

            $check = VatNumberCheck::query()->where('vat_number', strtoupper((string) $customer->vat_number))->latest('checked_at')->latest('id')->first();

            if ($check !== null && ! $check->valid) {
                throw ValidationException::withMessages(['invoice' => __('Kundens momsregistreringsnummer var ogiltigt vid senaste kontrollen i VIES. Kontrollera numret igen under Kunder.')]);
            }
        }

        if (! $sale->isEu() && ($country === 'SE' || ForeignSale::isEuCountry($country))) {
            throw ValidationException::withMessages(['invoice' => __('Kunden ska ha ett land utanför EU för export.')]);
        }

        Account::query()->firstOrCreate(['number' => $sale->account()], [
            'name' => __($sale->accountName()),
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
