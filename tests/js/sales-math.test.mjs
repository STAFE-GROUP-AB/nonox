// The same cases as tests/Feature/Invoicing, so browser and server agree.
// Run with: npm test (node --test, no packages needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseDiscount, parseQuantity, rowNet, totals } from '../../resources/js/sales-math.js';

test('reads quantities and discounts like the server', () => {
    assert.equal(parseQuantity('1,5'), 1500n);
    assert.equal(parseQuantity('12'), 12000n);
    assert.equal(parseQuantity('tre'), null);
    assert.equal(parseDiscount('12,5'), 1250);
    assert.equal(parseDiscount(''), 0);
    assert.equal(parseDiscount('101'), null);
});

test('a row is quantity × price less discount, rounded to öre', () => {
    assert.equal(rowNet({ quantity: '12', price: '24,90', discount: '10' }), 26892);
    assert.equal(rowNet({ quantity: '45', price: '1450', discount: '10' }), 5872500);
    assert.equal(rowNet({ quantity: '12', price: '24,90', discount: '10' }, true), -26892);
    assert.equal(rowNet({ quantity: '', price: '100' }), null);
});

test('VAT per rate on the sums, total rounded to whole kronor', () => {
    const result = totals([
        { quantity: '12', price: '119,00', vat_rate: '1200' },
        { quantity: '12', price: '24,90', vat_rate: '2500', discount: '10' },
    ]);

    assert.deepEqual(result, {
        net: 169692,
        vat: 23859,
        vatByRate: [{ rate: 2500, base: 26892, vat: 6723 }, { rate: 1200, base: 142800, vat: 17136 }],
        rounding: 49,
        total: 193600,
    });
});

test('the screenshot row: 45 hours at 1 450 kr less 10 % with 25 % VAT', () => {
    const result = totals([{ quantity: '45', price: '1450', discount: '10', vat_rate: '2500' }]);

    assert.equal(result.net, 5872500);
    assert.equal(result.vat, 1468125);
    assert.equal(result.rounding, -25);
    assert.equal(result.total, 7340600);
});

test('a credit invoice has negative totals', () => {
    assert.equal(totals([{ quantity: '12', price: '119,00', vat_rate: '1200' }], true).total, -159900);
});

test('an invoice in another currency is not rounded to whole units', () => {
    const result = totals([{ quantity: '45', price: '1450', discount: '10', vat_rate: '2500' }], false, false);

    assert.equal(result.rounding, 0);
    assert.equal(result.total, 7340625);
});
