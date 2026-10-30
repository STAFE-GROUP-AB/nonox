/**
 * The cost rows of a supplier invoice while it is being registered. Rows,
 * the total and the VAT live in the browser until the invoice is saved, so
 * typing is never slowed down by the server. "Kvar att fördela" shows what
 * the rows still lack to match the invoice; the server checks it again.
 */

import { formatOre, parseOre } from './money.js';

function blankRow() {
    return { account: '', amount: '', description: '', cost_center: '', project: '' };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('supplierRows', (rows, total, vat) => ({
        rows,
        total,
        vat,

        init() {
            if (this.rows.length === 0) {
                this.rows.push(blankRow());
            }
        },

        /** The total minus the VAT minus the rows, in öre; null while something cannot be read. */
        get remaining() {
            const total = parseOre(this.total);
            const vat = parseOre(this.vat);
            const amounts = this.rows.map((row) => parseOre(row.amount));

            if (total === null || vat === null || amounts.includes(null)) {
                return null;
            }

            return total - vat - amounts.reduce((sum, amount) => sum + amount, 0);
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
