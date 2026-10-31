<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherStatus;
use App\Models\FiscalYear;
use App\Models\VoucherRow;

/**
 * The year's result from the posted ledger, in signed öre (debit positive,
 * so a profit is negative): before tax, the tax on 89xx, and what is still
 * left on the income statement after the result has been booked on 8999.
 * One query.
 */
final readonly class YearResult
{
    public function __construct(
        public int $beforeTaxOre,
        public int $taxOre,
        public int $bookedOre,
    ) {}

    public static function of(FiscalYear $fiscalYear): self
    {
        $sums = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.fiscal_year_id', $fiscalYear->id)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('accounts.type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->selectRaw('CASE WHEN accounts.number = ? THEN 2 WHEN accounts.number BETWEEN 8900 AND 8989 THEN 1 ELSE 0 END AS part, SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) AS amount', [ClosingAccounts::YEAR_RESULT])
            ->groupByRaw('1')
            ->pluck('amount', 'part')
            ->map(fn (mixed $amount): int => (int) $amount);

        return new self((int) ($sums[0] ?? 0), (int) ($sums[1] ?? 0), (int) ($sums[2] ?? 0));
    }

    /** The result after tax; negative is a profit. */
    public function afterTaxOre(): int
    {
        return $this->beforeTaxOre + $this->taxOre;
    }

    /** What still has to be booked on 8999 for the income statement to close at zero. */
    public function unbookedOre(): int
    {
        return $this->afterTaxOre() + $this->bookedOre;
    }

    public function isBooked(): bool
    {
        return $this->unbookedOre() === 0;
    }

    public function hasActivity(): bool
    {
        return $this->beforeTaxOre !== 0 || $this->taxOre !== 0 || $this->bookedOre !== 0;
    }
}
