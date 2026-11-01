/**
 * Which rows of a voucher or a supplier invoice are purchases on one of the
 * company's acquisition accounts, and so may become an asset in the
 * register. No browser code here, so it can be tested with node --test. The
 * server checks the same rules again (AssetProposals).
 */

import { parseOre } from './money.js';

/** The leading account number of "1220 Inventarier" or "1220", or null. */
export function accountNumber(value) {
    const match = String(value ?? '').match(/^\s*(\d{4})/);

    return match ? Number(match[1]) : null;
}

export function inRanges(account, ranges) {
    return account !== null && ranges.some(([from, to]) => account >= from && account <= to);
}

/**
 * The debit of a row, in öre, when it is a purchase: a voucher row with a
 * debit and no credit, or a supplier invoice row with a positive amount.
 */
export function purchaseAmount(row, kind) {
    if (kind === 'voucher') {
        const debit = parseOre(row.debit);
        const credit = parseOre(row.credit);

        return debit !== null && debit > 0 && credit === 0 ? debit : null;
    }

    const amount = parseOre(row.amount);

    return amount !== null && amount > 0 ? amount : null;
}

/**
 * Every row that may become an asset, with its index in the form, its
 * account, its amount and whether it is under the company's threshold for
 * inventarier av mindre värde.
 */
export function acquisitionCandidates(rows, settings, kind) {
    const candidates = [];

    rows.forEach((row, index) => {
        const account = accountNumber(row.account);
        const amount = purchaseAmount(row, kind);

        if (amount === null || !inRanges(account, settings.ranges)) {
            return;
        }

        candidates.push({ index, row, account, amount, small: settings.threshold !== null && amount < settings.threshold });
    });

    return candidates;
}

/** The category whose asset account this is, or whose account group it is in; otherwise the first. */
export function categoryFor(account, categories) {
    return categories.find((category) => category.asset === account)
        ?? categories.find((category) => Math.floor(category.asset / 10) === Math.floor(account / 10))
        ?? categories[0];
}
