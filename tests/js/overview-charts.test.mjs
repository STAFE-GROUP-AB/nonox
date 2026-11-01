// The chart settings of Översikt → Statistik och prognos (ADR-027).
// Run with: npm test (node --test, no packages needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chartConfig, formatAxis, formatKronor, kronor } from '../../resources/js/overview-chart-configs.js';

const labels = { revenue: 'Intäkter 2026', costs: 'Kostnader 2026', revenuePrevious: 'Intäkter 2025', costsPrevious: 'Kostnader 2025', balance: 'Saldo', forecast: 'Prognos', result: 'Resultat', accumulated: 'Ackumulerat', invoiced: 'Fakturerat', cost: 'Kostnad', output: 'Utgående', input: 'Ingående', net: 'Netto' };

const stats = {
    months: ['jan', 'feb', 'mar'],
    revenue: { current: [500000, 0, null], previous: [100000, 200000, 300000] },
    costs: { current: [0, 200000, null], previous: [0, 0, 0] },
    result: { monthly: [500000, -200000, null], accumulated: [500000, 300000, null] },
    vat: { output: [125000, 0, null], input: [0, 50000, null] },
    liquidity: {
        actual: [{ label: 'jan 2026', balance: 500000 }, { label: 'I dag', balance: 300000 }],
        forecast: [{ label: 'mar 2026', balance: 400000 }, { label: 'apr 2026', balance: 450000 }],
    },
    top_customers: [{ name: 'Nordljus Konsult HB', amount: 1875000 }],
    top_accounts: [{ number: 5010, name: 'Lokalhyra', amount: 1200000 }],
};

test('draws öre as whole kronor and keeps months to come empty', () => {
    assert.equal(kronor(123456), 1235);
    assert.equal(kronor(null), null);
    assert.equal(formatKronor(18750), '18 750 kr');
    assert.equal(formatAxis(800000), '800 tkr');
    assert.equal(formatAxis(1200000), '1,2 mkr');
    assert.equal(formatAxis(500), '500 kr');
});

test('compares the year with last year as dashed lines', () => {
    const config = chartConfig('revenue', stats, labels);
    assert.deepEqual(config.data.datasets.map((set) => set.label), ['Intäkter 2026', 'Kostnader 2026', 'Intäkter 2025', 'Kostnader 2025']);
    assert.deepEqual(config.data.datasets[0].data, [5000, 0, null]);
    assert.deepEqual(config.data.datasets[2].borderDash, [6, 4]);
    assert.equal(config.data.datasets[2].borderColor, config.data.datasets[0].backgroundColor);
});

test('starts the forecast where the actual balance ends', () => {
    const config = chartConfig('liquidity', stats, labels);
    assert.deepEqual(config.data.labels, ['jan 2026', 'I dag', 'mar 2026', 'apr 2026']);
    assert.deepEqual(config.data.datasets[0].data, [5000, 3000, null, null]);
    assert.deepEqual(config.data.datasets[1].data, [null, 3000, 4000, 4500]);
    assert.equal(config.data.datasets[1].label, 'Prognos');
});

test('lays the largest customers and costs on their side, and VAT as output, input and net', () => {
    assert.equal(chartConfig('customers', stats, labels).options.indexAxis, 'y');
    assert.deepEqual(chartConfig('accounts', stats, labels).data.labels, ['5010 Lokalhyra']);
    assert.deepEqual(chartConfig('vat', stats, labels).data.datasets[2].data, [1250, -500, null]);
    assert.throws(() => chartConfig('pie', stats, labels));
});
