<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Models\OpeningBalance;
use App\Models\VoucherRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * An account's balance while bookkeeping (Kontosaldo): the opening balance
 * of the fiscal year plus the posted vouchers, never drafts. Amounts are
 * signed öre with debit positive; forDisplay() turns them into the
 * account's normal side, so a bank account with money in it and a supplier
 * debt are both positive. Every method is one query, however many accounts
 * or rows it covers.
 */
final class AccountBalances
{
    /**
     * The balance of each account at the end of $date: the opening balance
     * plus the year's posted vouchers dated up to and including $date.
     *
     * @return Collection<int, int> account number => signed öre
     */
    public static function atDate(int $fiscalYearId, CarbonInterface $date): Collection
    {
        $opening = OpeningBalance::query()
            ->where('opening_balances.fiscal_year_id', $fiscalYearId)
            ->select('opening_balances.account_id')
            ->selectRaw('opening_balances.amount_ore AS amount')
            ->toBase();

        $posted = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->where('vouchers.fiscal_year_id', $fiscalYearId)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereDate('vouchers.voucher_date', '<=', $date->toDateString())
            ->select('voucher_rows.account_id')
            ->selectRaw('voucher_rows.debit_ore - voucher_rows.credit_ore AS amount')
            ->toBase();

        return DB::query()
            ->fromSub($opening->unionAll($posted), 'balances')
            ->join('accounts', 'accounts.id', '=', 'balances.account_id')
            ->groupBy('accounts.number')
            ->selectRaw('accounts.number, SUM(balances.amount) AS balance')
            ->pluck('balance', 'number')
            ->mapWithKeys(fn (mixed $balance, mixed $number): array => [(int) $number => (int) $balance]);
    }

    /**
     * The balance of the account right after each of the given rows of
     * posted vouchers, in the general ledger's order (date, series, number,
     * row), so it is the same figure as the running balance in the Huvudbok.
     *
     * @param  iterable<VoucherRow>  $rows  rows of posted vouchers in one fiscal year
     * @return array<int, int> row id => signed öre
     */
    public static function afterRows(int $fiscalYearId, iterable $rows): array
    {
        $rows = collect($rows);

        if ($rows->isEmpty()) {
            return [];
        }

        $ledger = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('voucher_series', 'voucher_series.id', '=', 'vouchers.series_id')
            ->leftJoin('opening_balances', function ($join): void {
                $join->on('opening_balances.account_id', '=', 'voucher_rows.account_id')
                    ->on('opening_balances.fiscal_year_id', '=', 'vouchers.fiscal_year_id');
            })
            ->where('vouchers.fiscal_year_id', $fiscalYearId)
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereIn('voucher_rows.account_id', $rows->pluck('account_id')->unique()->values())
            ->selectRaw(<<<'SQL'
                voucher_rows.id AS row_id,
                COALESCE(opening_balances.amount_ore, 0) + SUM(voucher_rows.debit_ore - voucher_rows.credit_ore) OVER (
                    PARTITION BY voucher_rows.account_id
                    ORDER BY vouchers.voucher_date, voucher_series.code, vouchers.number, voucher_rows.id
                ) AS balance
                SQL)
            ->toBase();

        return DB::query()
            ->fromSub($ledger, 'ledger')
            ->whereIn('row_id', $rows->pluck('id')->values())
            ->pluck('balance', 'row_id')
            ->mapWithKeys(fn (mixed $balance, mixed $rowId): array => [(int) $rowId => (int) $balance])
            ->all();
    }

    /**
     * The balance after each row of a voucher that is not posted: the
     * balances at its date plus its own rows, in order.
     *
     * @param  iterable<VoucherRow>  $rows
     * @param  Collection<int, int>  $atDate  account number => signed öre, from atDate()
     * @return array<int, int> row id => signed öre
     */
    public static function afterDraftRows(iterable $rows, Collection $atDate): array
    {
        $running = $atDate->all();
        $result = [];

        foreach ($rows as $row) {
            $number = (int) $row->account?->number;
            $running[$number] = ($running[$number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
            $result[(int) $row->id] = $running[$number];
        }

        return $result;
    }

    /**
     * 1 for accounts whose balance is normally on the debit side (assets and
     * costs), -1 for those normally on the credit side (equity, liabilities
     * and revenue).
     */
    public static function sign(AccountType $type): int
    {
        return $type === AccountType::Asset || $type === AccountType::Expense ? 1 : -1;
    }

    /**
     * A signed balance (debit positive) as it is shown: positive when the
     * account has a balance on its normal side, negative when not, such as
     * an overdrawn bank account.
     */
    public static function forDisplay(int $ore, AccountType $type): int
    {
        return self::sign($type) * $ore;
    }
}
