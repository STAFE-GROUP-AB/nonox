// ADR-082 P3: the editor's price table picks the same step as the server,
// and the browser never overwrites a price typed or kept on a row.
// Run with: npm test (node --test, no packages needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { afterContextChange, consumerNet, grossVatChanged, discountInput, fromTier, quantityMilli, shouldFillPrice, tierFor } from '../../resources/js/price-table.js';
import { rowNet } from '../../resources/js/sales-math.js';

const table = {
    A1: {
        unit_id: 7,
        tiers: [
            { min: 10000, price: 9000, source: 'AGREEMENT', warnings: [] },
            { min: 1000, price: 10000, source: 'BASE', warnings: [] },
        ],
    },
};

test('the whole quantity gets the price of the highest step it reaches', () => {
    assert.equal(tierFor(table, 'A1', '7', 1000).price, 10000);
    assert.equal(tierFor(table, 'A1', 7, 9999).price, 10000);
    assert.equal(tierFor(table, 'A1', '7', 10000).price, 9000);
    assert.equal(tierFor(table, 'A1', '7', 12000).source, 'AGREEMENT');
});

test('below the lowest step the lowest step prices the row', () => {
    assert.equal(tierFor(table, 'A1', '7', 500).price, 10000);
});

test('another unit, an unknown article or an unreadable quantity is left to the server', () => {
    assert.equal(tierFor(table, 'A1', '8', 1000), null);
    assert.equal(tierFor(table, 'B2', '7', 1000), null);
    assert.equal(tierFor(table, 'A1', '7', null), null);
});

test('reads quantities like the server, without the sign', () => {
    assert.equal(quantityMilli('2,5'), 2500);
    assert.equal(quantityMilli('-3'), 3000);
    assert.equal(quantityMilli(''), 1000);
    assert.equal(quantityMilli('tre'), null);
});

test('writes discounts as the discount field does', () => {
    assert.equal(discountInput(0), '');
    assert.equal(discountInput(1000), '10');
    assert.equal(discountInput(1250), '12,5');
    assert.equal(discountInput(1205), '12,05');
});

test('a step gives the row its price and leaves a typed discount alone', () => {
    const row = { price: '', discount: '15', price_source: '' };
    Object.assign(row, fromTier({ min: 1000, price: 9000, source: 'AGREEMENT', warnings: ['converted_currency'] }));

    assert.equal(row.price, '90,00');
    assert.equal(row.discount, '15');
    assert.equal(row.price_source, 'AGREEMENT');
    assert.equal(row.price_warnings, 'converted_currency');
});

test('the agreed discount is in the price, so a typed discount gives the server\'s amount', () => {
    // 100 kr with 10 % agreed is 90 kr in the table; 15 % typed on top gives 76,50 kr, as the server folds it.
    const row = { quantity: '1', discount: '15', ...fromTier({ min: 1000, price: 9000, source: 'AGREEMENT', warnings: [] }) };

    assert.equal(rowNet(row), 7650);
});

test('a step without a source (no prices:view) still marks the row as priced by the table', () => {
    assert.equal(fromTier({ min: 1000, price: 100, source: '', warnings: [] }).price_source, 'AUTO');
    assert.deepEqual(fromTier(null), { price: '', price_source: '', price_warnings: '', price_explanation: '', price_gross_vat: '' });
});

test('leaving the article field fills the price only when it is empty or the article changed', () => {
    assert.equal(shouldFillPrice({ price: '75,00', price_source: 'MANUAL' }, false), false);
    assert.equal(shouldFillPrice({ price: '75,00', price_source: '' }, false), false);
    assert.equal(shouldFillPrice({ price: '90,00', price_source: 'AGREEMENT' }, false), false);
    assert.equal(shouldFillPrice({ price: '', price_source: '' }, false), true);
    assert.equal(shouldFillPrice({ price: '75,00', price_source: 'MANUAL' }, true), true);
});

test('a new customer, date or currency keeps the shown price but clears the table mark', () => {
    const tableRow = { article: 'A1', price: '90,00', price_source: 'AGREEMENT', price_explanation: '{}' };
    const manualRow = { article: 'A1', price: '75,00', price_source: 'MANUAL', price_explanation: '' };
    const freeRow = { article: '', price: '50,00', price_source: '', price_explanation: '' };

    assert.equal(afterContextChange(tableRow), true);
    assert.equal(afterContextChange(manualRow), false);
    assert.equal(afterContextChange(freeRow), false);
    assert.deepEqual(tableRow, { article: 'A1', price: '90,00', price_source: '', price_explanation: '' });
    assert.equal(manualRow.price_source, 'MANUAL');
});

test('a price from a list including VAT is worked out with the row\'s own VAT rate, rounded down like the server', () => {
    const tier = { min: 1000, price: 800, gross: 1001, source: 'LIST', warnings: [] };

    // 10,01 kr including VAT: 25 % gives 8,00 kr (the article's rate), 12 % gives 8,93 kr, 0 % the gross price itself.
    assert.equal(fromTier(tier, '2500').price, '8,00');
    assert.equal(fromTier(tier, '1200').price, '8,93');
    assert.equal(fromTier(tier, 600).price, '9,44');
    assert.equal(fromTier(tier, '0').price, '10,01');
    assert.equal(consumerNet(1001, 1200), 893);
    assert.equal(consumerNet(-1001, 2500), -801);
});

test('a tier without a gross price, or a row without a VAT rate, keeps the tier\'s own price', () => {
    assert.equal(fromTier({ min: 1000, price: 9000, gross: null, source: 'AGREEMENT', warnings: [] }, '1200').price, '90,00');
    assert.equal(fromTier({ min: 1000, price: 800, gross: 1001, source: 'LIST', warnings: [] }, '').price, '8,00');
    assert.equal(fromTier({ min: 1000, price: 800, gross: 1001, source: 'LIST', warnings: [] }).price, '8,00');
});

test('a saved row priced from a list including VAT shows the hint when its VAT rate changes', () => {
    assert.equal(grossVatChanged({ price_gross_vat: '600', vat_rate: '2500', price_source: 'BASE' }), true);
    // Unchanged rate, a typed price, a list excluding VAT (no flag): no hint.
    assert.equal(grossVatChanged({ price_gross_vat: '600', vat_rate: '600', price_source: 'BASE' }), false);
    assert.equal(grossVatChanged({ price_gross_vat: '600', vat_rate: '2500', price_source: 'MANUAL' }), false);
    assert.equal(grossVatChanged({ price_gross_vat: '', vat_rate: '2500', price_source: 'BASE' }), false);
    assert.equal(grossVatChanged({ vat_rate: '2500', price_source: 'BASE' }), false);
    // Priced again in this session: the flag is cleared, so no hint.
    assert.equal(fromTier({ price: 800, gross: 1001, source: 'BASE', warnings: [] }, '2500').price_gross_vat, '');
});
