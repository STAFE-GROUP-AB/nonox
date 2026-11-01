<?php

declare(strict_types=1);

namespace App\Domain\Overview\Todos;

use App\Domain\Accounting\VoucherStatus;
use App\Domain\Overview\Contracts\TodoProvider;
use App\Domain\Overview\TodoItem;
use App\Models\BankReconciliation;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\User;
use App\Models\VoucherRow;
use Carbon\CarbonImmutable;

/**
 * The bank: incoming payments from bank files that are not matched to an
 * invoice yet, and the company account (1930) when it has not been
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
        return [BankTransaction::class];
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
