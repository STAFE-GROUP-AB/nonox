/**
 * Warnings when a VAT rate or a voucher's output VAT does not fit the
 * revenue account, like App\Domain\Vat\SalesVatRules (#62). Only warnings:
 * the VAT return follows the VAT actually booked. No browser code here, so
 * it can be tested with node --test.
 *
 * rules: account number => { rates: [basis points], vat: [output VAT account numbers] | null }
 */

import { accountNumber } from './account-balance.js';
import { parseOre } from './money.js';

function hasAmount(row) {
    return (parseOre(row.debit) ?? 0) !== 0 || (parseOre(row.credit) ?? 0) !== 0;
}

/**
 * The sales accounts on a voucher that expect output VAT the voucher does not
 * book, in the order of the rows: [{ account, vat: [account numbers] }].
 */
export function voucherVatWarnings(rows, rules) {
    const used = new Set(rows.filter(hasAmount).map((row) => accountNumber(row)).filter((number) => number !== null));
    const warnings = [];

    for (const number of used) {
        const vat = rules[number]?.vat;

        if (vat && !vat.some((account) => used.has(String(account)))) {
            warnings.push({ account: number, vat });
        }
    }

    return warnings;
}

/**
 * Invoice, quote or order rows whose VAT rate does not fit the revenue
 * account they are booked on (the row's own, or its article's):
 * [{ line, account, rate, rates }]. Nothing when the document carries no VAT.
 */
export function salesRowVatWarnings(rows, articles, rules, vatFree = false) {
    if (vatFree) {
        return [];
    }

    const warnings = [];

    rows.forEach((row, index) => {
        const article = articles[String(row.article ?? '').trim().split(' ')[0]];
        const account = String(row.account ?? '').trim() || (article?.account ? String(article.account) : '');
        const rule = rules[account];
        const rate = Number(row.vat_rate);

        if (rule && String(row.description ?? '').trim() !== '' && !rule.rates.includes(rate)) {
            warnings.push({ line: index + 1, account, rate, rates: rule.rates });
        }
    });

    return warnings;
}

/** "25 %" from basis points. */
export function ratePercent(basisPoints) {
    return `${Math.trunc(Number(basisPoints) / 100)} %`;
}
