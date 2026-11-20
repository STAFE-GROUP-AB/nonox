/**
 * The rows of an invoice, quote or order while they are being written. Rows
 * live in the browser until the document is saved, so stepping through the
 * fields never waits for, or is overwritten by, the server. An article number
 * fills the row from the article. Totals follow every keystroke.
 *
 * Prices (ADR-082 P3): an editor that prices its rows loads a price table
 * for its customer, date and currency from the server (priceTable()), and
 * again when one of them changes; picking an article or changing a quantity
 * takes the price from it at once. A price typed by hand is marked MANUAL
 * and never replaced. The server prices the rows again when the document is
 * saved; its result is what is stored.
 */

import { formatOre } from './money.js';
import { rowNet, totals } from './sales-math.js';
import { ratePercent, salesRowVatWarnings } from './vat-rate-check.js';
import { afterContextChange, fromTier, quantityMilli, shouldFillPrice, tierFor } from './price-table.js';

/**
 * A new row, in the company's default unit (a unit of its register, by id),
 * with the customer's default cost centre and project (ADR-081 gap 6).
 */
function blankRow(unitId, defaults = {}) {
    return { article: '', description: '', quantity: '1', unit_id: unitId, price: '', discount: '', vat_rate: '2500', account: '', cost_center: defaults.cost_center ?? '', project: defaults.project ?? '', price_source: '', price_explanation: '', price_warnings: '' };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('salesRows', (rows, articles, negative, vatFree = false, currency = () => 'SEK', vatRules = {}, defaultUnitId = '', dimensions = {}, customer = () => '', pricing = {}) => ({
        // Sales accounts and the VAT rates they take (App\Domain\Vat\SalesVatRules, #62).
        vatRules,
        rows,
        articles,
        negative,
        // Omvänd betalningsskyldighet or a sale abroad: the invoice carries no VAT, whatever the rows say.
        vatFree,
        // An invoice in another currency is written in it, and its total is not rounded to whole kronor.
        currency,
        defaultUnitId,
        // Cost centres of projects and the customers' default dimensions, by code (ADR-081 gap 6).
        projectCostCenters: dimensions.projectCostCenters ?? {},
        customerDimensions: dimensions.customers ?? {},
        customer,
        // The price engine (ADR-082 P3): on in the invoice, quote and order editors.
        pricingEnabled: pricing.enabled ?? false,
        markers: pricing.markers ?? false,
        canExplain: pricing.canExplain ?? false,
        sourceLabels: pricing.labels ?? {},
        warningTexts: pricing.warnings ?? {},
        priceTable: {},
        pricesStale: false,
        // The article each row had when its article field got focus, so leaving the field unchanged keeps the price.
        articleAtFocus: {},
        // Where focus goes back to when "Varför detta pris?" or "Uppdatera priser" closes.
        returnTo: null,

        get currencyCode() {
            return String(this.currency() || 'SEK').toUpperCase();
        },

        init() {
            if (this.rows.length === 0) {
                this.rows.push(blankRow(this.defaultUnitId, this.customerDefaults()));
            }

            // A newly chosen customer gives its dimensions to the rows that have none yet.
            if (this.$wire && typeof this.$wire.$watch === 'function') {
                this.$wire.$watch('customerId', () => this.applyCustomer());

                // A new customer, date or currency brings a new price table; the rows keep their prices.
                (pricing.watch ?? []).forEach((property) => this.$wire.$watch(property, () => this.loadPrices(true)));
            }

            this.loadPrices(false);
        },

        /**
         * The price table for the rows' articles (and $numbers, one just
         * picked), merged into what the browser has. After a new customer,
         * date or currency ($changed) the rows keep exactly the price shown:
         * their table mark is cleared, so a price that differs from the new
         * one is saved as typed by hand, and the notice offers "Uppdatera
         * priser" (ADR-082 §6, as built in P3).
         */
        async loadPrices(changed, numbers = []) {
            if (!this.pricingEnabled || !this.$wire || typeof this.$wire.priceTable !== 'function') {
                return;
            }

            const table = (await this.$wire.priceTable(numbers)) ?? {};
            this.priceTable = changed ? table : { ...this.priceTable, ...table };

            if (!changed) {
                return;
            }

            let affected = false;

            this.rows.forEach((row) => {
                affected = afterContextChange(row) || affected;
            });

            this.pricesStale = affected;
        },

        articleNumber(row) {
            return String(row.article ?? '').trim().split(' ')[0];
        },

        /** The table's step for the row, or null when the table cannot price it (another unit, no entry). */
        tier(row) {
            return tierFor(this.priceTable, this.articleNumber(row), row.unit_id, quantityMilli(row.quantity));
        },

        hasTableEntry(row) {
            return Object.prototype.hasOwnProperty.call(this.priceTable, this.articleNumber(row));
        },

        /** Takes the row's price from the table; a discount typed on the row stays. A row the table cannot price gets its price when saved. */
        priceFromTable(row) {
            Object.assign(row, fromTier(this.tier(row)));
        },

        /** A new quantity or unit re-prices a row whose price came from the table, never one typed by hand or kept from before. */
        repriceRow(index) {
            const row = this.rows[index];

            if (!this.pricingEnabled || !row || !row.article || row.price_source === 'MANUAL' || row.price_source === '' || !this.hasTableEntry(row)) {
                return;
            }

            this.priceFromTable(row);
        },

        /** A price typed by hand is kept as typed; an emptied price is fetched again. */
        markManual(index) {
            const row = this.rows[index];

            row.price_source = String(row.price ?? '').trim() === '' ? '' : 'MANUAL';
            row.price_explanation = '';
            row.price_warnings = '';
        },

        rememberArticle(index) {
            this.articleAtFocus[index] = this.rows[index]?.article ?? '';
        },

        rememberFocus(element) {
            this.returnTo = element;
        },

        /** After the panel closes (Livewire re-renders first), focus goes back where it came from. */
        returnFocusSoon() {
            const element = this.returnTo;

            setTimeout(() => {
                if (element && element.isConnected) {
                    element.focus();
                }
            }, 250);
        },

        sourceLabel(source) {
            return this.sourceLabels[source] ?? '';
        },

        priceWarnings(row) {
            return String(row.price_warnings ?? '').split(',').filter((warning) => warning !== '');
        },

        warningText(warning) {
            return this.warningTexts[warning] ?? warning;
        },

        explain(index, element = null) {
            if (this.canExplain && this.$wire) {
                this.rememberFocus(element);
                this.$wire.explainPrice(index);
            }
        },

        /** The chosen customer's default cost centre and project. */
        customerDefaults() {
            return this.customerDimensions[String(this.customer() ?? '')] ?? {};
        },

        applyCustomer() {
            const defaults = this.customerDefaults();

            this.rows.forEach((row) => {
                if (!row.cost_center && defaults.cost_center) {
                    row.cost_center = defaults.cost_center;
                }

                if (!row.project && defaults.project) {
                    row.project = defaults.project;
                    this.fillProjectRow(row);
                }
            });
        },

        /** A project with a default cost centre fills the row's empty cost centre. */
        fillProject(index) {
            this.fillProjectRow(this.rows[index]);
        },

        fillProjectRow(row) {
            const costCenter = this.projectCostCenters[row.project ?? ''];

            if (!row.cost_center && costCenter) {
                row.cost_center = costCenter;
            }
        },

        get totals() {
            const vatFree = typeof this.vatFree === 'function' ? this.vatFree() : this.vatFree;
            const rows = vatFree ? this.rows.map((row) => ({ ...row, vat_rate: '0' })) : this.rows;

            return totals(rows, this.negative, this.currencyCode === 'SEK');
        },

        /** Rows whose VAT rate does not fit their revenue account: a warning, not a block. */
        get vatWarnings() {
            const vatFree = typeof this.vatFree === 'function' ? this.vatFree() : this.vatFree;

            return salesRowVatWarnings(this.rows, this.articles, this.vatRules, vatFree);
        },

        percent(basisPoints) {
            return ratePercent(basisPoints);
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
            this.rows.push(blankRow(this.defaultUnitId, this.customerDefaults()));
            this.$nextTick(() => this.$root.querySelectorAll('[data-row-article]')[this.rows.length - 1]?.focus());
        },

        removeRow(index) {
            this.rows.splice(index, 1);

            if (this.rows.length === 0) {
                this.rows.push(blankRow(this.defaultUnitId, this.customerDefaults()));
            }
        },

        /** Fills the row from the article whose number was typed or chosen. */
        fillArticle(index) {
            const row = this.rows[index];
            const article = this.articles[String(row.article ?? '').trim().split(' ')[0]];

            if (!article) {
                return;
            }

            // Leaving the article field unchanged keeps the price: a typed or saved price changes only with "Uppdatera priser" (ADR-082 §6).
            const changed = !Object.prototype.hasOwnProperty.call(this.articleAtFocus, index) || this.articleAtFocus[index] !== row.article;
            const fillPrice = shouldFillPrice(row, changed);
            this.articleAtFocus[index] = row.article;

            row.description = article.name;
            row.unit_id = article.unit_id ? String(article.unit_id) : row.unit_id;

            if (fillPrice && this.pricingEnabled) {
                // The customer's price for the date and currency (ADR-082 P3); an article not in the table yet is fetched first.
                if (this.hasTableEntry(row)) {
                    this.priceFromTable(row);
                } else {
                    const number = this.articleNumber(row);
                    Object.assign(row, fromTier(null));
                    this.loadPrices(false, [number]).then(() => {
                        // The rows may have been replaced while the table loaded: fill the row by its place, if it still has that article and no price.
                        const current = this.rows[index];

                        if (current && this.articleNumber(current) === number && String(current.price ?? '').trim() === '' && this.hasTableEntry(current)) {
                            this.priceFromTable(current);
                        }
                    });
                }
            } else if (fillPrice) {
                // Article prices are kronor; in another currency the price is left for the person to write.
                row.price = this.currencyCode === 'SEK' ? formatOre(article.price) : row.price;
            }

            row.vat_rate = String(article.vat_rate);
            row.account = '';

            // The article's cost centre and project come before the customer's (ADR-081 gap 6).
            if (article.cost_center) {
                row.cost_center = article.cost_center;
            }

            if (article.project) {
                row.project = article.project;
            }

            this.fillProjectRow(row);

            if (String(row.quantity ?? '').trim() === '') {
                row.quantity = '1';
            }
        },
    }));
});
