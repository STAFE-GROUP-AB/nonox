/**
 * Chart.js settings for Översikt → Statistik och prognos (ADR-027). Pure
 * functions, without Chart.js itself, so they can be tested with node.
 * Amounts arrive in öre and are drawn in kronor.
 *
 * One fixed palette, checked for colour blindness and contrast against white
 * (dataviz validator): vermilion, blue and ochre, always in that order. Last
 * year and the forecast are dashed lines in the same colour as their
 * counterpart, so they never depend on colour alone.
 */

export const COLORS = { first: '#d4330b', second: '#1f6fb8', third: '#a67800', grid: '#e7e5e4', text: '#57534e' };

const kronorFormat = new Intl.NumberFormat('sv-SE', { style: 'currency', currency: 'SEK', maximumFractionDigits: 0 });

/** Öre to whole kronor, null kept (a month that has not come yet). */
export function kronor(ore) {
    return ore === null || ore === undefined ? null : Math.round(ore / 100);
}

/** "12 345 kr" as people in Sweden write it. */
export function formatKronor(value) {
    return kronorFormat.format(value).replace(/ /g, ' ');
}

const decimalFormat = new Intl.NumberFormat('sv-SE', { maximumFractionDigits: 1 });

/** Axis ticks kept short: "800 tkr", "1,2 mkr". */
export function formatAxis(value) {
    const size = Math.abs(value);

    if (size >= 1_000_000) {
        return `${decimalFormat.format(value / 1_000_000)} mkr`;
    }

    if (size >= 1_000) {
        return `${decimalFormat.format(value / 1_000)} tkr`;
    }

    return formatKronor(value);
}

/** "31,4 %" as people in Sweden write it. */
export function formatPercent(value) {
    return `${decimalFormat.format(value)} %`;
}

function bars(label, data, color) {
    return { type: 'bar', label, data: data.map(kronor), backgroundColor: color, borderRadius: 4, maxBarThickness: 18, borderSkipped: 'start' };
}

function line(label, data, color, dashed = false) {
    return {
        type: 'line', label, data: data.map(kronor), borderColor: color, backgroundColor: color, borderWidth: 2,
        borderDash: dashed ? [6, 4] : [], pointStyle: dashed ? 'dash' : 'line', pointRadius: 0, pointHoverRadius: 5, pointHitRadius: 12, tension: 0, spanGaps: false,
    };
}

function options(horizontal = false) {
    const valueAxis = {
        grid: { color: COLORS.grid }, border: { display: false },
        ticks: { color: COLORS.text, callback: (value) => formatAxis(value), maxTicksLimit: horizontal ? 5 : 6 },
    };
    const categoryAxis = { grid: { display: false }, ticks: { color: COLORS.text } };
    const reduceMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: reduceMotion ? false : { duration: 300 },
        indexAxis: horizontal ? 'y' : 'x',
        interaction: { mode: horizontal ? 'nearest' : 'index', intersect: horizontal },
        scales: horizontal ? { x: valueAxis, y: categoryAxis } : { x: categoryAxis, y: valueAxis },
        plugins: {
            legend: { display: !horizontal, position: 'bottom', labels: { color: COLORS.text, usePointStyle: true, boxHeight: 8 } },
            tooltip: {
                callbacks: {
                    label: (context) => `${context.dataset.label}: ${formatKronor(context.parsed[horizontal ? 'x' : 'y'])}`,
                },
            },
        },
    };
}

/**
 * The parts of one forecast month, each as it changes the balance:
 * "Kundfakturor: +12 500 kr". Parts that are zero are left out; nothing
 * for a point that is not a forecast.
 *
 * @param {object|undefined} point a point of data.liquidity.forecast
 * @param {object} labels the part names, translated on the server
 * @returns {string[]}
 */
export function forecastParts(point, labels) {
    if (!point) {
        return [];
    }

    const parts = [
        [labels.customerInvoices, point.inflow],
        [labels.supplierInvoices, -point.outflow],
        [labels.recurring, point.recurring ?? 0],
        [labels.payroll, point.payroll ?? 0],
        [labels.vatFlow, point.vat ?? 0],
        [labels.taxAccount, point.tax_account ?? 0],
        [labels.trend, point.trend],
    ];

    return parts
        .filter(([, ore]) => kronor(ore) !== 0)
        .map(([label, ore]) => `${label}: ${ore > 0 ? '+' : ''}${formatKronor(kronor(ore))}`);
}

/**
 * The Chart.js configuration of one chart.
 *
 * @param {string} kind revenue | result | liquidity | customers | accounts | vat | staffing | staffing-percent
 * @param {object} data the figures from App\Domain\Overview\Statistics
 * @param {object} labels the series names, translated on the server
 */
export function chartConfig(kind, data, labels) {
    switch (kind) {
        case 'revenue': {
            const datasets = [bars(labels.revenue, data.revenue.current, COLORS.first), bars(labels.costs, data.costs.current, COLORS.second)];

            if (data.revenue.previous) {
                datasets.push(line(labels.revenuePrevious, data.revenue.previous, COLORS.first, true), line(labels.costsPrevious, data.costs.previous, COLORS.second, true));
            }

            return { type: 'bar', data: { labels: data.months, datasets }, options: options() };
        }
        case 'result':
            return {
                type: 'bar',
                data: { labels: data.months, datasets: [bars(labels.result, data.result.monthly, COLORS.third), line(labels.accumulated, data.result.accumulated, COLORS.second)] },
                options: options(),
            };
        case 'liquidity': {
            const actual = data.liquidity.actual;
            const forecast = data.liquidity.forecast;
            const names = [...actual.map((point) => point.label), ...forecast.map((point) => point.label)];
            const actualValues = [...actual.map((point) => point.balance), ...forecast.map(() => null)];
            // The forecast starts at today's balance, so the two lines meet.
            const forecastValues = [...actual.map((point, index) => (index === actual.length - 1 ? point.balance : null)), ...forecast.map((point) => point.balance)];

            const config = {
                type: 'line',
                data: { labels: names, datasets: [line(labels.balance, actualValues, COLORS.second), line(labels.forecast, forecastValues, COLORS.second, true)] },
                options: options(),
            };
            // A forecast month's tooltip shows what moves the line, so the owner can see why.
            config.options.plugins.tooltip.callbacks.afterBody = (items) => forecastParts(forecast[(items[0]?.dataIndex ?? -1) - actual.length], labels);

            return config;
        }
        case 'customers':
            return {
                type: 'bar',
                data: { labels: data.top_customers.map((row) => row.name), datasets: [bars(labels.invoiced, data.top_customers.map((row) => row.amount), COLORS.first)] },
                options: options(true),
            };
        case 'accounts':
            return {
                type: 'bar',
                data: { labels: data.top_accounts.map((row) => `${row.number} ${row.name}`), datasets: [bars(labels.cost, data.top_accounts.map((row) => row.amount), COLORS.second)] },
                options: options(true),
            };
        case 'vat': {
            const net = data.vat.output.map((value, index) => (value === null ? null : value - (data.vat.input[index] ?? 0)));

            return {
                type: 'bar',
                data: {
                    labels: data.months,
                    datasets: [bars(labels.output, data.vat.output, COLORS.first), bars(labels.input, data.vat.input, COLORS.second), line(labels.net, net, COLORS.third)],
                },
                options: options(),
            };
        }
        case 'staffing': {
            // Planering → Bemanning och kostnad: estimated labour cost (planned, actual) as bars, sales from the books as a line.
            const datasets = [bars(labels.planned, data.staffing.planned, COLORS.first)];

            if (data.staffing.actual) {
                datasets.push(bars(labels.actual, data.staffing.actual, COLORS.second));
            }

            datasets.push(line(labels.sales, data.staffing.sales, COLORS.third));

            if (data.staffing.lastYearSales) {
                datasets.push(line(labels.lastYearSales, data.staffing.lastYearSales, COLORS.third, true));
            }

            return { type: 'bar', data: { labels: data.staffing.labels, datasets }, options: options() };
        }
        case 'staffing-percent': {
            // Days without sales have no share; points keep a lone day visible.
            const percentLine = (label, values, color, dashed = false) => ({ ...line(label, [], color, dashed), data: values, pointRadius: 3 });
            const datasets = [percentLine(labels.planned, data.staffing.plannedPercent, COLORS.first)];

            if (data.staffing.actualPercent) {
                datasets.push(percentLine(labels.actual, data.staffing.actualPercent, COLORS.second));
            }

            if (data.staffing.lastYearPercent) {
                datasets.push(percentLine(labels.lastYear, data.staffing.lastYearPercent, COLORS.first, true));
            }

            const config = options();
            config.scales.y.ticks.callback = (value) => formatPercent(value);
            config.scales.y.beginAtZero = true;
            config.plugins.tooltip.callbacks.label = (context) => `${context.dataset.label}: ${context.parsed.y === null ? '–' : formatPercent(context.parsed.y)}`;

            return { type: 'line', data: { labels: data.staffing.labels, datasets }, options: config };
        }
        default:
            throw new Error(`Unknown chart ${kind}`);
    }
}
