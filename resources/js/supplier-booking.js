/**
 * The voucher a supplier invoice becomes when it is registered, worked out in
 * the browser as a preview: the cost rows, the VAT and the supplier liability.
 * Positive amounts are debit, in öre. The server books it with
 * App\Domain\Payables\SupplierInvoiceBooking; the two must agree.
 */

import { parseOre } from './money.js';

/**
 * @param {{rows: Array<{account: string, amount: string, vat_rate: string}>, total: string, vat: string, treatment: string, credit: boolean, accounts: {names: Object, vat: Object, payables: number, inputVat: number}}} invoice
 * @returns {Array<{account: number, amount: number, name: string}>|null} null when an amount cannot be read
 */
export function supplierVoucher({ rows, total, vat, treatment, credit, accounts }) {
    const totalOre = parseOre(total);
    const vatOre = treatment === 'DOMESTIC' ? parseOre(vat) : 0;
    const amounts = rows.map((row) => parseOre(row.amount));

    if (totalOre === null || vatOre === null || amounts.includes(null)) {
        return null;
    }

    const sign = credit ? -1 : 1;
    const lines = rows.map((row, index) => ({ account: Number(row.account), amount: sign * amounts[index] }));
    const buyer = accounts.vat[treatment];

    if (treatment === 'DOMESTIC' && vatOre > 0) {
        lines.push({ account: accounts.inputVat, amount: sign * vatOre });
    }

    if (treatment !== 'DOMESTIC' && buyer) {
        const sums = {};

        rows.forEach((row, index) => {
            sums[row.vat_rate] = (sums[row.vat_rate] ?? 0) + amounts[index];
        });

        for (const [rate, sum] of Object.entries(sums)) {
            const amount = Math.sign(sum) * Math.round(Math.abs(sum) * Number(rate) / 10000);

            if (amount !== 0) {
                lines.push({ account: buyer.input, amount: sign * amount });
                lines.push({ account: buyer.output[rate] ?? buyer.output[2500], amount: -sign * amount });
            }
        }
    }

    lines.push({ account: accounts.payables, amount: -sign * totalOre });

    return lines.map((line) => ({ ...line, name: accounts.names[line.account] ?? '' }));
}
