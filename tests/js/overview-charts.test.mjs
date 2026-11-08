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

test('shows in the tooltip what moves a forecast month, and nothing for the months behind', () => {
    const parts = { ...labels, customerInvoices: 'Kundfakturor', supplierInvoices: 'Leverantörsfakturor', recurring: 'Avtalsfakturor', payroll: 'Lön och arbetsgivarskatter', vatFlow: 'Moms', taxAccount: 'Skattekontot', trend: 'Snittresultat' };
    const withParts = {
        ...stats,
        liquidity: {
            ...stats.liquidity,
            forecast: [
                { label: 'mar 2026', balance: 400000, inflow: 1250000, outflow: 0, recurring: 0, payroll: -4200000, vat: -250000, tax_account: 30, trend: 700000 },
                { label: 'apr 2026', balance: 450000, inflow: 0, outflow: 0, recurring: 0, payroll: 0, vat: 0, tax_account: 0, trend: 0 },
            ],
        },
    };
    const afterBody = chartConfig('liquidity', withParts, parts).options.plugins.tooltip.callbacks.afterBody;

    assert.deepEqual(afterBody([{ dataIndex: 2 }]), ['Kundfakturor: +12 500 kr', 'Lön och arbetsgivarskatter: −42 000 kr', 'Moms: −2 500 kr', 'Snittresultat: +7 000 kr']);
    assert.deepEqual(afterBody([{ dataIndex: 3 }]), []);
    assert.deepEqual(afterBody([{ dataIndex: 1 }]), []);
});

test('lays the largest customers and costs on their side, and VAT as output, input and net', () => {
    assert.equal(chartConfig('customers', stats, labels).options.indexAxis, 'y');
    assert.deepEqual(chartConfig('accounts', stats, labels).data.labels, ['5010 Lokalhyra']);
    assert.deepEqual(chartConfig('vat', stats, labels).data.datasets[2].data, [1250, -500, null]);
    assert.throws(() => chartConfig('pie', stats, labels));
});

test('draws Bemanning och kostnad: cost bars, sales line, last year dashed, shares in percent', () => {
    const staffing = { labels: ['v. 45', 'v. 46'], planned: [88314, 0], actual: [90000, null], sales: [1399900, null], lastYearSales: [1000000, 900000], plannedPercent: [6.3, null], actualPercent: [6.4, null], lastYearPercent: null };
    const names = { planned: 'Planerad', actual: 'Faktisk', sales: 'Försäljning', lastYearSales: 'Försäljning förra året', lastYear: 'Förra året' };
    const cost = chartConfig('staffing', { staffing }, names);

    assert.deepEqual(cost.data.datasets.map((set) => [set.type, set.label, set.borderDash ?? null]), [['bar', 'Planerad', null], ['bar', 'Faktisk', null], ['line', 'Försäljning', []], ['line', 'Försäljning förra året', [6, 4]]]);
    assert.deepEqual(cost.data.datasets[0].data, [883, 0]);

    const share = chartConfig('staffing-percent', { staffing }, names);
    assert.equal(share.data.datasets.length, 2);
    assert.deepEqual(share.data.datasets[0].data, [6.3, null]);
    assert.equal(share.options.scales.y.ticks.callback(10), '10 %');
});
