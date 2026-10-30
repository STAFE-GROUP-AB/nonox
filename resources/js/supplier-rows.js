/**
 * The cost rows of a supplier invoice while it is being registered. Rows,
 * the total and the VAT live in the browser until the invoice is saved, so
 * typing is never slowed down by the server. "Kvar att fördela" shows what
 * the rows still lack to match the invoice; the server checks it again.
 */

import { formatOre, parseOre } from './money.js';
import { supplierVoucher } from './supplier-booking.js';

function blankRow() {
    return { account: '', amount: '', description: '', cost_center: '', project: '', vat_rate: '2500' };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('supplierRows', (rows, total, vat, treatment, credit = false, accounts = null) => ({
        rows,
        total,
        vat,
        treatment,
        credit,
        accounts,

        /** Whether the buyer accounts for the VAT, so the invoice itself carries none. */
        get buyerLiable() {
            return this.treatment !== 'DOMESTIC';
        },

        /** The VAT the buyer accounts for: each rate on the sum of its rows. */
        get buyerVat() {
            const sums = {};

            for (const row of this.rows) {
                const amount = parseOre(row.amount);

                if (amount === null) {
                    return null;
                }

                sums[row.vat_rate] = (sums[row.vat_rate] ?? 0) + amount;
            }

            return Object.entries(sums).reduce((vat, [rate, sum]) => vat + Math.sign(sum) * Math.round(Math.abs(sum) * Number(rate) / 10000), 0);
        },

        init() {
            if (this.rows.length === 0) {
                this.rows.push(blankRow());
            }
        },

        /** The total minus the VAT minus the rows, in öre; null while something cannot be read. */
        get remaining() {
            const total = parseOre(this.total);
            const vat = this.buyerLiable ? 0 : parseOre(this.vat);
            const amounts = this.rows.map((row) => parseOre(row.amount));

            if (total === null || vat === null || amounts.includes(null)) {
                return null;
            }

            return total - vat - amounts.reduce((sum, amount) => sum + amount, 0);
        },

        /** The whole voucher the invoice becomes when registered; null until the rows add up. */
        get voucher() {
            if (this.accounts === null || this.remaining !== 0) {
                return null;
            }

            return supplierVoucher(this);
        },

        format(ore) {
            return formatOre(ore);
        },

        addRow() {
            this.rows.push(blankRow());
            this.$nextTick(() => this.$root.querySelectorAll('[data-row-account]')[this.rows.length - 1]?.focus());
        },

        removeRow(index) {
            this.rows.splice(index, 1);

            if (this.rows.length === 0) {
                this.rows.push(blankRow());
            }
        },

        /** Puts what is left to distribute on the row, on top of what it has. */
        fillRest(index) {
            const remaining = this.remaining;

            if (remaining === null || remaining === 0) {
                return;
            }

            this.rows[index].amount = formatOre((parseOre(this.rows[index].amount) ?? 0) + remaining);
        },

        /** With a single empty row, the row takes the whole amount once total and VAT are written. */
        fillSingleRow() {
            if (this.rows.length === 1 && String(this.rows[0].amount ?? '').trim() === '' && parseOre(this.total)) {
                this.fillRest(0);
            }
        },
    }));
});
