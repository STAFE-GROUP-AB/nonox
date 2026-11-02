<?php

declare(strict_types=1);

namespace App\Actions\Invoicing;

use App\Domain\Invoicing\IncomingPaymentMatcher;
use App\Domain\Invoicing\InvoiceBooking;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Books incoming payments from a bank file on the invoices they are matched
 * to, each on the bank's booking date (series C, through RegisterPayment),
 * and lets the user change which invoice a payment belongs to before that.
 *
 * On an invoice in another currency the kronor received are booked against
 * the amount the payer sent in that currency, when the bank reported it in
 * the invoice's currency; otherwise the payment settles what remains, and
 * the difference to the kronor is an exchange gain or loss (ADR-025).
 */
class BookIncomingPayments
{
    public function __construct(private readonly RegisterPayment $register) {}

    /**
     * @param  list<int>  $transactionIds
     */
    public function __invoke(User $user, Company $company, array $transactionIds): int
    {
        return CurrentCompany::run($company, fn (): int => DB::transaction(function () use ($user, $transactionIds): int {
            $transactions = BankTransaction::query()->whereIn('id', $transactionIds)->lockForUpdate()->get();
            $booked = 0;

            foreach ($transactions as $transaction) {
                if ($transaction->isBooked() || $transaction->invoice_id === null) {
                    continue;
                }

                $invoice = Invoice::query()->findOrFail($transaction->invoice_id);
                $payment = ($this->register)($user, $invoice, $transaction->booking_date, $transaction->amount_ore, InvoiceBooking::BANK, self::foreignAmount($invoice, $transaction));

                $transaction->forceFill(['invoice_payment_id' => $payment->id])->save();
                $booked++;
            }

            return $booked;
        }));
    }

    public function assign(User $user, Company $company, BankTransaction $transaction, ?string $invoiceId): void
    {
        CurrentCompany::run($company, function () use ($user, $company, $transaction, $invoiceId): void {
            abort_unless($user->hasTeamPermission($company, 'payments:create'), 403);

            if ($transaction->isBooked()) {
                throw ValidationException::withMessages(['transaction' => __('Inbetalningen är redan bokförd.')]);
            }

            if ($invoiceId !== null) {
                $invoice = Invoice::query()->findOrFail($invoiceId);

                if (! IncomingPaymentMatcher::fits($invoice, $transaction->amount_ore->ore, $transaction->foreign_currency, $transaction->foreign_amount_minor)) {
                    throw ValidationException::withMessages(['transaction' => __('Beloppet är större än det som återstår på :title.', ['title' => $invoice->title()])]);
                }
            }

            $transaction->forceFill(['invoice_id' => $invoiceId])->save();
        });
    }

    /** The amount in the invoice's currency the payment pays, when the bank reported one; null otherwise. */
    private static function foreignAmount(Invoice $invoice, BankTransaction $transaction): ?int
    {
        if (! $invoice->isForeign()) {
            return null;
        }

        return $transaction->foreign_currency === $invoice->invoice_currency ? $transaction->foreign_amount_minor : null;
    }
}
