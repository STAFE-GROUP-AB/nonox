// ADR-082 §6: a row loaded from a saved document is never re-priced by a field
// change (Antal, Enhet, Moms); only a row the price table priced in this
// editing session is, and on a VAT change only when the step has a gross price.
// Run with: npm test (node --test, no packages needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';

let factory = null;
globalThis.document = { addEventListener: (_event, callback) => callback() };
globalThis.window = { Alpine: { data: (_name, make) => { factory = make; } } };

await import('../../resources/js/sales-rows.js');

const grossTable = { B1: { unit_id: 1, tiers: [{ min: 1000, price: 800, gross: 1001, source: 'LIST', warnings: [] }, { min: 10000, price: 700, gross: 876, source: 'LIST', warnings: [] }] } };
const netTable = { A1: { unit_id: 1, tiers: [{ min: 1000, price: 9000, source: 'AGREEMENT', warnings: [] }, { min: 10000, price: 8000, source: 'AGREEMENT', warnings: [] }] } };

function editor(rows, table) {
    const data = factory(rows, {}, false, false, () => 'SEK', {}, '1', {}, () => '', { enabled: true });
    data.priceTable = table;

    return data;
}

/** A row as a saved document opens it: priced by the table at the time, marked with that source. */
const saved = (extra = {}) => ({ article: 'B1', description: 'B1', quantity: '1', unit_id: '1', price: '9,00', discount: '', vat_rate: '2500', price_source: 'LIST', price_explanation: '', price_warnings: '', ...extra });

test('a row loaded from storage keeps its price when Antal, Enhet or Moms change', () => {
    const data = editor([saved({ quantity: '20' })], grossTable);

    data.repriceRow(0);
    data.repriceRow(0, 'vat');

    assert.equal(data.rows[0].price, '9,00');
    assert.equal(data.rows[0].price_source, 'LIST');
});

test('a row priced by the table in this session is re-priced by Antal, and by Moms only with a gross price', () => {
    const data = editor([saved({ price: '', price_source: '' })], grossTable);

    data.priceFromTable(data.rows[0]);
    assert.equal(data.rows[0].price, '8,00');

    data.rows[0].vat_rate = '1200';
    data.repriceRow(0, 'vat');
    assert.equal(data.rows[0].price, '8,93');

    data.rows[0].quantity = '20';
    data.repriceRow(0);
    assert.equal(data.rows[0].price, '7,82');
});

test('a session-priced row on a list without gross prices keeps its price when Moms changes, but follows Antal', () => {
    const data = editor([saved({ article: 'A1', price: '', price_source: '' })], netTable);

    data.priceFromTable(data.rows[0]);
    data.rows[0].vat_rate = '1200';
    data.repriceRow(0, 'vat');
    assert.equal(data.rows[0].price, '90,00');

    data.rows[0].quantity = '20';
    data.repriceRow(0);
    assert.equal(data.rows[0].price, '80,00');
});

test('a price typed by hand is never re-priced, also in this session', () => {
    const data = editor([saved({ price: '', price_source: '' })], grossTable);

    data.priceFromTable(data.rows[0]);
    data.rows[0].price = '5,00';
    data.markManual(0);
    data.rows[0].quantity = '20';
    data.repriceRow(0);

    assert.equal(data.rows[0].price, '5,00');
    assert.equal(data.rows[0].price_source, 'MANUAL');
});
