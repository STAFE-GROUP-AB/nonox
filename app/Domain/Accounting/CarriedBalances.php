<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * The opening balances a year should have after the year before it: the
 * closing balance of every balance-sheet account, with a result not yet
 * booked to equity on the result account, so they sum to zero (ADR-010).
 * Pure arithmetic on account numbers, shared by CarryForwardBalances, which
 * writes them, and StaleOpeningBalances, which compares them with what is
 * stored.
 */
final class CarriedBalances
{
    /**
     * @param  array<int, AccountType>  $types  every account of the chart, by number
     * @param  array<int, int>  $opening  the year's opening balances, by account number
     * @param  array<int, int>  $movements  the year's posted debit minus credit, by account number
     * @return array<int, int> the next year's opening balances, by account number, without zeros
     */
    public static function closing(array $types, array $opening, array $movements, int $resultNumber): array
    {
        $balances = [];

        foreach ($types as $number => $type) {
            if (! LedgerBalances::isBalanceSheet($type)) {
                continue;
            }

            $closing = ($opening[$number] ?? 0) + ($movements[$number] ?? 0);

            if ($closing !== 0) {
                $balances[$number] = $closing;
            }
        }

        $unbooked = array_sum($balances);

        if ($unbooked !== 0) {
            $balances[$resultNumber] = ($balances[$resultNumber] ?? 0) - $unbooked;
        }

        return array_filter($balances, fn (int $amount): bool => $amount !== 0);
    }

    /**
     * The result not yet booked to equity, as CarryForwardBalances records it
     * (credit negative): minus the sum of the balance-sheet closing balances.
     *
     * @param  array<int, AccountType>  $types
     * @param  array<int, int>  $opening
     * @param  array<int, int>  $movements
     */
    public static function unbookedResult(array $types, array $opening, array $movements): int
    {
        $sum = 0;

        foreach ($types as $number => $type) {
            if (LedgerBalances::isBalanceSheet($type)) {
                $sum += ($opening[$number] ?? 0) + ($movements[$number] ?? 0);
            }
        }

        return -$sum;
    }
}
