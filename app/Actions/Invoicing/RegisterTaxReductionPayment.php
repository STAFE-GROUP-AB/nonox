<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\TaxReduction;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Registers Skatteverket's payment of a ROT or RUT deduction: the bank
 * account against 1513, in series C like other incoming payments.
 *
 * TODO(legal-verify): a claim that Skatteverket refuses in part is the
 * customer's to pay; moving the remainder back to the customer is not
 * handled yet and is booked by hand.
 */
class RegisterTaxReductionPayment
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, Invoice $invoice, CarbonImmutable $paidOn, Money $amount, int $bankAccount = InvoiceBooking::BANK): Invoice
    {
        Gate::forUser($user)->authorize('registerTaxReductionPayment', $invoice);

        return CurrentCompany::run($invoice->company_id, fn (): Invoice => DB::transaction(function () use ($user, $invoice, $paidOn, $amount, $bankAccount): Invoice {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $company = Company::query()->findOrFail($invoice->company_id);
            $left = $invoice->tax_reduction_ore->ore - $invoice->tax_reduction_paid_ore->ore;

            if ($invoice->tax_reduction === null || $invoice->status === InvoiceStatus::Draft || $invoice->isCredit()) {
                throw ValidationException::withMessages(['amount' => __('Fakturan har inget ROT- eller RUT-avdrag att få betalt för.')]);
            }

            if ($amount->ore <= 0 || $amount->ore > $left) {
                throw ValidationException::withMessages(['amount' => __('Beloppet ska vara mellan 1 öre och det som återstår, :left.', ['left' => Money::ofOre($left)->format()])]);
            }

            $voucher = ($this->bookVoucher)(
                $user,
                $company,
                $paidOn,
                __(':type från Skatteverket, :title', ['type' => TaxReduction::label($invoice->tax_reduction), 'title' => $invoice->title()]),
                'C',
                [$bankAccount => $amount->ore, TaxReduction::RECEIVABLE => -$amount->ore],
                VoucherSource::Payment,
                $invoice,
            );

            $invoice->forceFill(['tax_reduction_paid_ore' => $invoice->tax_reduction_paid_ore->ore + $amount->ore])->save();
            Audit::record('invoice.tax_reduction_paid', $invoice, ['amount_ore' => $amount->ore, 'voucher' => $voucher->reference()], $company, $user);

            return $invoice;
        }));
    }
}
