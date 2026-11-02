<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Invoicing\InvoiceBooking;
use App\Domain\Invoicing\TaxReduction;
use App\Models\Account;
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
 * Registers that the company has paid back to Skatteverket a ROT or RUT
 * deduction that a credit invoice took back from the buyer: 2890 against
 * the bank account, in series C. Skatteverket decides the repayment and it
 * is paid into a special account (lag (2009:194) 14-16 §§) (enligt
 * granskning 2026-11-02, juristgranskning återstår (AUD-FAKT-9, #49)).
 */
class RegisterTaxReductionRepayment
{
    public function __construct(private readonly BookVoucher $bookVoucher) {}

    public function __invoke(User $user, Invoice $credit, CarbonImmutable $paidOn, Money $amount, int $bankAccount = InvoiceBooking::BANK): Invoice
    {
        Gate::forUser($user)->authorize('registerTaxReductionRepayment', $credit);

        return CurrentCompany::run($credit->company_id, fn (): Invoice => DB::transaction(function () use ($user, $credit, $paidOn, $amount, $bankAccount): Invoice {
            /** @var Invoice $credit */
            $credit = Invoice::query()->whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $company = Company::query()->findOrFail($credit->company_id);
            $left = $credit->taxReductionRepaymentLeft()->ore;

            if ($amount->ore <= 0 || $amount->ore > $left) {
                throw ValidationException::withMessages(['repay_amount' => __('Beloppet ska vara mellan 1 öre och det som återstår, :left.', ['left' => Money::ofOre($left)->format()])]);
            }

            if (! Account::query()->where('number', $bankAccount)->exists()) {
                throw ValidationException::withMessages(['repay_amount' => __('Konto :number finns inte i kontoplanen.', ['number' => $bankAccount])]);
            }

            $voucher = ($this->bookVoucher)(
                $user,
                $company,
                $paidOn,
                __('Återbetalning av :type till Skatteverket, :title', ['type' => TaxReduction::label((string) $credit->tax_reduction), 'title' => $credit->title()]),
                'C',
                [TaxReduction::REPAYABLE => $amount->ore, $bankAccount => -$amount->ore],
                VoucherSource::Payment,
                $credit,
            );

            $credit->forceFill(['tax_reduction_repaid_ore' => $credit->tax_reduction_repaid_ore->ore + $amount->ore])->save();
            Audit::record('invoice.tax_reduction_repaid', $credit, ['amount_ore' => $amount->ore, 'voucher' => $voucher->reference()], $company, $user);

            return $credit;
        }));
    }
}
