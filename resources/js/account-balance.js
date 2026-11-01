/**
 * Kontosaldo while a voucher is entered, like App\Domain\Accounting\AccountBalances:
 * the posted balance at the voucher date plus this voucher's rows, shown on the
 * account's normal side (assets and costs as debit, equity, liabilities and
 * revenue as credit). No browser code here, so it can be tested with node --test.
 */

import { parseOre } from './money.js';

/** The four-digit account number at the start of "1930 Företagskonto", or null. */
export function accountNumber(row) {
    const match = String(row?.account ?? '').match(/^\s*(\d{4})/);

    return match ? match[1] : null;
}

/**
 * The balance before this voucher, on the account's normal side. Null when it
 * cannot be known: no account chosen, an account not in the chart, or a date
 * outside every fiscal year (balances null).
 *
 * @param {object} row
 * @param {Record<string, number>|null} balances account number => signed öre, debit positive
 * @param {Record<string, number>} signs account number => 1 (debit side) or -1 (credit side)
 */
export function balanceBefore(row, balances, signs) {
    const number = accountNumber(row);

    if (number === null || balances === null || !(number in signs)) {
        return null;
    }

    return (balances[number] ?? 0) * signs[number];
}

/**
 * The balance after each row would be posted: the balance before plus this
 * row and the rows above it on the same account. Unreadable amounts count as
 * zero; the editor marks them separately.
 *
 * @returns {Array<number|null>}
 */
export function balancesAfterRows(rows, balances, signs) {
    const running = {};

    return rows.map((row) => {
        const number = accountNumber(row);

        if (number === null || balances === null || !(number in signs)) {
            return null;
        }

        running[number] = (running[number] ?? balances[number] ?? 0)
            + (parseOre(row.debit) ?? 0)
            - (parseOre(row.credit) ?? 0);

        return running[number] * signs[number];
    });
}
