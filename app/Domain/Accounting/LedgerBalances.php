<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Account balances of a fiscal year, in signed öre (debit positive), keyed by
 * account id: UB = IB + the year's posted movements; an income statement
 * account's result is its movements (ADR-008).
 */
final class LedgerBalances
{
    /**
     * @return Collection<int, int>
     */
    public static function opening(FiscalYear $fiscalYear): Collection
    {
        return OpeningBalance::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->pluck('amount_ore', 'account_id')
            ->map(fn (Money $amount): int => $amount->ore);
    }

    /**
     * Debit minus credit per account for the posted vouchers of a year.
     *
     * @return Collection<int, int>
     */
    public static function movements(FiscalYear $fiscalYear): Collection
    {
        return VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->groupBy('voucher_rows.account_id')
            ->selectRaw('voucher_rows.account_id, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS movement')
            ->pluck('movement', 'account_id')
            ->map(fn (mixed $movement): int => (int) $movement);
    }

    public static function isBalanceSheet(AccountType $type): bool
    {
        return $type === AccountType::Asset || $type === AccountType::LiabilityEquity;
    }
}
