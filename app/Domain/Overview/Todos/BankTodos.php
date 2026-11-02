<?php

declare(strict_types=1);

namespace App\Domain\Overview\Todos;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Domain\Payables\PaymentBatchStatus;
use App\Domain\Payables\PaymentResponseStatus;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\PaymentResponse;
use App\Models\SupplierPaymentBatchItem;
use App\Models\User;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The bank: incoming payments from bank files that are not matched to an
 * invoice yet, answers to payment files to handle and payments the bank
 * rejected, and the company account (1930) when it has not been
 * reconciled against the bank statement up to the end of last month.
 */
final class BankTodos implements TodoProvider
{
    public const int ACCOUNT = 1930;

    public function todos(User $user, Company $company, CarbonImmutable $today): array
    {
        $items = [];

        if ($user->hasTeamPermission($company, 'payments:create')) {
            $unmatched = BankTransaction::query()->whereNull('invoice_id')->count();

            if ($unmatched > 0) {
                $items[] = new TodoItem(
                    'bank-transactions',
                    'bank',
                    $unmatched === 1 ? __('1 bankpost att stämma av') : __(':count bankposter att stämma av', ['count' => $unmatched]),
                    __('Inbetalningar från bankfilen som inte är kopplade till en faktura.'),
                    route('invoicing.incomingPayments'),
                    TodoItem::PRIORITY_BANK,
                );
            }
        }

        if ($user->hasTeamPermission($company, 'payments:create')) {
            array_push($items, ...$this->paymentResponses());
        }

        if ($user->hasTeamPermission($company, 'vouchers:post')) {
            $monthEnd = $today->startOfMonth()->subDay();
            $reconciled = BankReconciliation::query()
                ->join('accounts', 'accounts.id', '=', 'bank_reconciliations.account_id')
                ->where('accounts.number', self::ACCOUNT)
                ->max('bank_reconciliations.statement_date');

            $due = $reconciled === null || CarbonImmutable::parse((string) $reconciled)->lessThan($monthEnd);

            if ($due && $this->hasBankRowsUntil($monthEnd)) {
                $items[] = new TodoItem(
                    'bank-reconciliation',
                    'bank',
                    __('Stäm av företagskontot för :month', ['month' => $monthEnd->translatedFormat('F')]),
                    $reconciled === null
                        ? __('Konto :account har inte stämts av mot kontoutdraget ännu.', ['account' => self::ACCOUNT])
                        : __('Avstämt till och med :date.', ['date' => CarbonImmutable::parse((string) $reconciled)->translatedFormat('j F')]),
                    route('accounting.bankReconciliation', ['konto' => self::ACCOUNT, 'datum' => $monthEnd->toDateString()]),
                    TodoItem::PRIORITY_BANK + 1,
                );
            }
        }

        return $items;
    }

    public function invalidatedBy(): array
    {
        return [BankTransaction::class, PaymentResponse::class, SupplierPaymentBatchItem::class];
    }

    /**
     * The bank's answers to payment files that wait for a person, and
     * payments the bank rejected whose invoice is still unpaid and not in a
     * new file (ADR-014). Two queries.
     *
     * @return list<TodoItem>
     */
    private function paymentResponses(): array
    {
        $items = [];
        $open = PaymentResponse::query()->whereIn('status', [PaymentResponseStatus::Suggested->value, PaymentResponseStatus::Unmatched->value])->count();

        if ($open > 0) {
            $items[] = new TodoItem(
                'payment-responses',
                'bank',
                $open === 1 ? __('1 svar från banken om betalfiler att hantera') : __(':count svar från banken om betalfiler att hantera', ['count' => $open]),
                __('Bekräfta eller avfärda det NONOX inte kunde göra automatiskt.'),
                route('payables.batches.index'),
                TodoItem::PRIORITY_PAY,
            );
        }

        $rejected = SupplierPaymentBatchItem::query()
            ->where('bank_status', SupplierPaymentBatchItem::REJECTED)
            ->whereHas('invoice', fn (Builder $invoice) => $invoice
                ->where('status', SupplierInvoiceStatus::Approved->value)
                ->whereDoesntHave('batchItems', fn (Builder $item) => $item
                    ->whereNull('supplier_payment_id')
                    ->where(fn (Builder $status) => $status->whereNull('bank_status')->orWhere('bank_status', '!=', SupplierPaymentBatchItem::REJECTED))
                    ->whereHas('batch', fn (Builder $batch) => $batch->where('status', PaymentBatchStatus::Created->value))))
            ->distinct()
            ->count('supplier_invoice_id');

        if ($rejected > 0) {
            $items[] = new TodoItem(
                'payment-rejected',
                'bank',
                $rejected === 1 ? __('1 betalning avvisad av banken') : __(':count betalningar avvisade av banken', ['count' => $rejected]),
                __('Fakturan är obetald igen. Se bankens orsak, rätta och betala på nytt.'),
                route('payables.batches.index'),
                TodoItem::PRIORITY_PAY,
            );
        }

        return $items;
    }

    /** Whether anything is booked on the company account up to the date. */
    private function hasBankRowsUntil(CarbonImmutable $date): bool
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('accounts.number', self::ACCOUNT)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
            ->exists();
    }
}
