/**
 * Keyboard-first voucher entry (AGENTS.md §11.2). Calculates the balance live;
 * the server checks every rule again when the voucher is saved or posted.
 */

import { balanceBefore, balancesAfterRows } from './account-balance.js';
import { formatOre, parseOre } from './money.js';

function isBlank(row) {
    return !row.account && !row.debit && !row.credit && !row.description;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('voucherEditor', (rows, canPost, balances, signs) => ({
        rows,
        canPost,
        // Posted balances at the voucher date (account number => öre, debit positive), from the server.
        balances,
        signs,
        showHelp: false,
        confirmingPost: false,
        confirmingLeave: false,

        init() {
            this.ensureBlankRow();
            this.$watch('rows', () => this.ensureBlankRow());
        },

        get debit() {
            return this.rows.reduce((sum, row) => sum + Math.max(parseOre(row.debit) ?? 0, 0), 0);
        },

        get credit() {
            return this.rows.reduce((sum, row) => sum + Math.max(parseOre(row.credit) ?? 0, 0), 0);
        },

        get difference() {
            return this.debit - this.credit;
        },

        get hasInvalidAmount() {
            return this.rows.some((row) => parseOre(row.debit) === null || parseOre(row.credit) === null);
        },

        /** Rows with an amount but no account chosen. */
        get missingAccount() {
            return this.rows.some((row) => !String(row.account ?? '').match(/^\s*\d{4}/) && (row.debit || row.credit));
        },

        get filledRows() {
            return this.rows.filter((row) => !isBlank(row)).length;
        },

        get balanced() {
            return !this.hasInvalidAmount && !this.missingAccount && this.filledRows >= 2 && this.debit > 0 && this.difference === 0;
        },

        /** Kontosaldo after each row, in the order of the rows. */
        get balancesAfter() {
            return balancesAfterRows(this.rows, this.balances, this.signs);
        },

        balanceBefore(row) {
            return balanceBefore(row, this.balances, this.signs);
        },

        format(ore) {
            return formatOre(ore);
        },

        invalid(value) {
            return parseOre(value) === null;
        },

        ensureBlankRow() {
            const last = this.rows[this.rows.length - 1];

            if (!last || !isBlank(last)) {
                this.rows.push({ account: '', debit: '', credit: '', description: '', cost_center: '', project: '' });
            }
        },

        removeRow(index) {
            if (this.rows.length > 1) {
                this.rows.splice(index, 1);
            }
        },

        /** Enter moves to the next field, like Tab. */
        focusNext(event) {
            const fields = [...this.$root.querySelectorAll('[data-grid]')];
            const next = fields[fields.indexOf(event.target) + 1];

            if (next) {
                this.$nextTick(() => next.focus());
            }
        },

        leave() {
            window.location.href = this.$root.dataset.backUrl;
        },

        requestPost() {
            if (this.canPost && this.balanced) {
                this.confirmingPost = true;
            }
        },

        shortcuts(event) {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);

            if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
                event.preventDefault();
                this.requestPost();
            } else if (event.key === 'Escape') {
                if (this.confirmingPost || this.showHelp || this.confirmingLeave) {
                    this.confirmingPost = false;
                    this.showHelp = false;
                    this.confirmingLeave = false;
                } else if (this.filledRows > 0) {
                    this.confirmingLeave = true;
                } else {
                    this.leave();
                }
            } else if (event.key === '?' && !typing) {
                event.preventDefault();
                this.showHelp = !this.showHelp;
            }
        },
    }));
});
