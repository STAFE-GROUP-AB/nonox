/**
 * Keyboard-first voucher entry (AGENTS.md §11.2). Calculates the balance live;
 * the server checks every rule again when the voucher is saved or posted.
 */

const NBSP = ' ';

/** Parses Swedish amounts like the server's Money::parse. Returns öre or null. */
export function parseOre(value) {
    const text = String(value ?? '')
        .replace(/[\s  ]/g, '')
        .replace('−', '-')
        .replace(/(kr\.?|sek)$/i, '');

    if (text === '') {
        return 0;
    }

    const match = text.match(/^(-?)(\d+)(?:[.,](\d{1,2}))?$/);

    if (!match) {
        return null;
    }

    const ore = Number(match[2]) * 100 + Number((match[3] ?? '').padEnd(2, '0'));

    return match[1] === '-' ? -ore : ore;
}

export function formatOre(ore) {
    const sign = ore < 0 ? '-' : '';
    const absolute = Math.abs(ore);
    const kronor = String(Math.floor(absolute / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
    const rest = String(absolute % 100).padStart(2, '0');

    return `${sign}${kronor},${rest}`;
}

function isBlank(row) {
    return !row.account && !row.debit && !row.credit && !row.description;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('voucherEditor', (rows, canPost) => ({
        rows,
        canPost,
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
