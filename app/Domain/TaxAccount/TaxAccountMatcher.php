<?php

declare(strict_types=1);

namespace App\Domain\TaxAccount;

/**
 * Pairs the transactions on the tax account at Skatteverket with what is
 * booked on account 1630 (ADR-054 stage 3). Conservative on purpose: a
 * transaction counts as booked only when NONOX booked it from the tax
 * account, or a posted voucher moves 1630 by exactly the same amount on the
 * same day (the transaction day, else the interest day). A day's
 * transactions that together equal one voucher on that day count as booked
 * too, since one voucher often books the employer declaration's tax and
 * contributions, which the tax account shows as two transactions. Each
 * voucher is used once. A corrected voucher is never used, nor a correction
 * that only reverses it; a transaction whose booking was corrected may be
 * booked again ("previous" names the corrected voucher). Everything else is
 * left for a person to look at, on both sides.
 */
final class TaxAccountMatcher
{
    /** Booked in NONOX from the tax account (TaxAccountPosting). */
    public const string BOOKED = 'booked';

    /** A voucher with the same amount on the same day. */
    public const string MATCHED = 'matched';

    /** Together with the day's other transactions, one voucher. */
    public const string MATCHED_SUM = 'matched_sum';

    /** Not found in the books: a posting can be proposed. */
    public const string MISSING = 'missing';

    /** Kronofogden's part or no amount: booked by hand, never proposed. */
    public const string MANUAL = 'manual';

    /**
     * @param  list<array{id: int, date: string, interestDate: ?string, text: string, skatteverket: ?int, kronofogden: ?int}>  $transactions
     * @param  list<array{voucher_id: string, reference: string, date: string, amount_ore: int, corrected_by?: ?string}>  $entries  each voucher's net movement on 1630, debit positive
     * @param  array<int, array{voucher_id: ?string, reference: string, corrected?: bool}>  $booked  the vouchers NONOX booked from the tax account, by transaction id
     * @return array{transactions: list<array{id: int, date: string, interestDate: ?string, text: string, skatteverket: ?int, kronofogden: ?int, status: string, reference: ?string, previous: ?string}>, unmatched: list<array{voucher_id: string, reference: string, date: string, amount_ore: int}>}
     */
    public static function match(array $transactions, array $entries, array $booked): array
    {
        $pool = [];

        foreach ($entries as $entry) {
            $pool[$entry['voucher_id']] = ['voucher_id' => $entry['voucher_id'], 'reference' => $entry['reference'], 'date' => $entry['date'], 'amount_ore' => $entry['amount_ore']];
        }

        // A corrected voucher and a correction that only reverses it cancel out, and neither stands for a transaction.
        foreach ($entries as $entry) {
            $correction = $entry['corrected_by'] ?? null;

            if ($correction === null) {
                continue;
            }

            if (isset($pool[$correction]) && $pool[$correction]['amount_ore'] === -$entry['amount_ore']) {
                unset($pool[$correction]);
            }

            unset($pool[$entry['voucher_id']]);
        }

        $result = [];

        foreach ($transactions as $index => $transaction) {
            $earlier = $booked[$transaction['id']] ?? null;
            $previous = $earlier !== null && ($earlier['corrected'] ?? false) ? $earlier['reference'] : null;
            $result[$index] = [...$transaction, 'status' => self::MISSING, 'reference' => null, 'previous' => $previous];

            if ($earlier !== null && $previous === null) {
                $result[$index] = [...$transaction, 'status' => self::BOOKED, 'reference' => $earlier['reference'], 'previous' => null];
                unset($pool[(string) $earlier['voucher_id']]);

                continue;
            }

            if (($transaction['kronofogden'] ?? 0) !== 0 || ($transaction['skatteverket'] ?? 0) === 0) {
                $result[$index] = [...$transaction, 'status' => self::MANUAL, 'reference' => null, 'previous' => $previous];
            }
        }

        // One transaction, one voucher: the transaction day first, then the interest day.
        foreach (['date', 'interestDate'] as $day) {
            foreach ($result as $index => $transaction) {
                if ($transaction['status'] !== self::MISSING || $transaction[$day] === null) {
                    continue;
                }

                $key = self::find($pool, $transaction[$day], self::ore($transaction));

                if ($key !== null) {
                    $result[$index] = [...$transaction, 'status' => self::MATCHED, 'reference' => $pool[$key]['reference']];
                    unset($pool[$key]);
                }
            }
        }

        // Several transactions of one day, one voucher of that day.
        $days = [];

        foreach ($result as $index => $transaction) {
            if ($transaction['status'] === self::MISSING) {
                $days[$transaction['date']][] = $index;
            }
        }

        foreach ($days as $date => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $sum = array_sum(array_map(fn (int $index): int => self::ore($result[$index]), $indexes));
            $key = self::find($pool, (string) $date, $sum);

            if ($key === null) {
                continue;
            }

            foreach ($indexes as $index) {
                $result[$index] = [...$result[$index], 'status' => self::MATCHED_SUM, 'reference' => $pool[$key]['reference']];
            }

            unset($pool[$key]);
        }

        return ['transactions' => array_values($result), 'unmatched' => array_values($pool)];
    }

    /**
     * The transaction's amount on 1630 in öre: money in at Skatteverket is a debit on 1630.
     *
     * @param  array{skatteverket?: ?int}  $transaction
     */
    public static function ore(array $transaction): int
    {
        return (int) ($transaction['skatteverket'] ?? 0) * 100;
    }

    /** @param  array<string, array{voucher_id: string, reference: string, date: string, amount_ore: int}>  $pool */
    private static function find(array $pool, string $date, int $amount): ?string
    {
        if ($amount === 0) {
            return null;
        }

        foreach ($pool as $key => $entry) {
            if ($entry['date'] === $date && $entry['amount_ore'] === $amount) {
                return (string) $key;
            }
        }

        return null;
    }
}
