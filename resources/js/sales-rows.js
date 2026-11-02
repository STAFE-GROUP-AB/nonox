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
    window.Alpine.data('salesRows', (rows, articles, negative, vatFree = false, currency = () => 'SEK') => ({
        rows,
        articles,
        negative,
        // Omvänd betalningsskyldighet or a sale abroad: the invoice carries no VAT, whatever the rows say.
        vatFree,
        // An invoice in another currency is written in it, and its total is not rounded to whole kronor.
        currency,

        get currencyCode() {
            return String(this.currency() || 'SEK').toUpperCase();
        },

        init() {
            if (this.rows.length === 0) {
                this.rows.push(blankRow());
            }
        },

        get totals() {
            const vatFree = typeof this.vatFree === 'function' ? this.vatFree() : this.vatFree;
            const rows = vatFree ? this.rows.map((row) => ({ ...row, vat_rate: '0' })) : this.rows;

            return totals(rows, this.negative, this.currencyCode === 'SEK');
        },

        net(row) {
            const net = rowNet(row, this.negative);

            return net === null ? '' : formatOre(net);
        },

        format(ore) {
            return formatOre(ore);
        },

        /** An amount with its currency: "1 250,00 kr" or "1 250,00 EUR". */
        money(minor) {
            return formatOre(minor) + (this.currencyCode === 'SEK' ? ' kr' : ' ' + this.currencyCode);
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
            // Article prices are kronor; in another currency the price is left for the person to write.
            row.price = this.currencyCode === 'SEK' ? formatOre(article.price) : row.price;
            row.vat_rate = String(article.vat_rate);
            row.account = '';

            if (String(row.quantity ?? '').trim() === '') {
                row.quantity = '1';
            }
        },
    }));
});
