/**
 * The rows of an invoice, quote or order while they are being written. Rows
 * live in the browser until the document is saved, so stepping through the
 * fields never waits for, or is overwritten by, the server. An article number
 * fills the row from the article. Totals follow every keystroke.
 */

import { formatOre } from './money.js';
import { rowNet, totals } from './sales-math.js';

function blankRow() {
    return { article: '', description: '', quantity: '1', unit: 'st', price: '', discount: '', vat_rate: '2500', account: '' };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('salesRows', (rows, articles, negative) => ({
        rows,
        articles,
        negative,

        init() {
            if (this.rows.length === 0) {
                this.rows.push(blankRow());
            }
        },

        get totals() {
            return totals(this.rows, this.negative);
        },

        net(row) {
            const net = rowNet(row, this.negative);

            return net === null ? '' : formatOre(net);
        },

        format(ore) {
            return formatOre(ore);
        },

        addRow() {
            this.rows.push(blankRow());
            this.$nextTick(() => this.$root.querySelectorAll('[data-row-article]')[this.rows.length - 1]?.focus());
        },

        removeRow(index) {
            this.rows.splice(index, 1);

            if (this.rows.length === 0) {
                this.rows.push(blankRow());
            }
        },

        /** Fills the row from the article whose number was typed or chosen. */
        fillArticle(index) {
            const row = this.rows[index];
            const article = this.articles[String(row.article ?? '').trim().split(' ')[0]];

            if (!article) {
                return;
            }

            row.description = article.name;
            row.unit = article.unit;
            row.price = formatOre(article.price);
            row.vat_rate = String(article.vat_rate);
            row.account = '';

            if (String(row.quantity ?? '').trim() === '') {
                row.quantity = '1';
            }
        },
    }));
});
