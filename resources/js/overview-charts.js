/**
 * Draws a chart on Översikt → Statistik och prognos (ADR-027). Chart.js
 * (MIT) is bundled by Vite in its own file and only fetched when the tab is
 * opened; nothing is loaded from another site.
 */

import { chartConfig } from './overview-chart-configs.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('overviewChart', (kind, data, labels) => ({
        chart: null,

        async init() {
            const { default: Chart } = await import('chart.js/auto');
            this.chart = new Chart(this.$refs.canvas, chartConfig(kind, data, labels));
        },

        destroy() {
            this.chart?.destroy();
        },
    }));

    // The tabs of Översikt, kept in the address (?flik=statistik) so a reload or a shared link opens the same tab.
    window.Alpine.data('overviewTabs', (initial) => ({
        tab: initial,

        show(tab) {
            this.tab = tab;
            const url = new URL(window.location.href);

            if (tab === 'oversikt') {
                url.searchParams.delete('flik');
            } else {
                url.searchParams.set('flik', tab);
            }

            window.history.replaceState(window.history.state, '', url);
        },
    }));
});
