/**
 * The panel under the rows of a voucher or a supplier invoice that offers to
 * create an asset in the register from a row booked to an acquisition
 * account. The proposal is kept on the row (row.asset) and saved with the
 * draft; the asset is created when the voucher is posted or the invoice is
 * registered.
 */

import { formatOre } from './money.js';
import { acquisitionCandidates, categoryFor } from './asset-candidates.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('assetProposals', (settings, kind) => ({
        settings,
        kind,

        candidates(rows, blocked = false) {
            return blocked ? [] : acquisitionCandidates(rows, this.settings, this.kind);
        },

        start(item, fallbackName) {
            const category = categoryFor(item.account, this.settings.categories);

            item.row.asset = {
                name: String(item.row.description ?? '').trim() || String(fallbackName ?? '').trim(),
                category: category.value,
                acquisition: '',
                life_months: String(category.life),
                acquired_on: '',
                depreciation_start: '',
            };
        },

        remove(item) {
            item.row.asset = null;
        },

        categoryChanged(item) {
            const category = this.settings.categories.find((option) => option.value === item.row.asset.category);

            if (category) {
                item.row.asset.life_months = String(category.life);
            }
        },

        format(ore) {
            return formatOre(ore);
        },
    }));
});
