// ADR-082 §9 (P7): the browser asks the server about the margin only after
// something that moves it has changed, and sends nothing but what the server
// reads. Run with: npm test (node --test, no packages needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { marginPayload, marginSignature } from '../../resources/js/margin-check.js';

const row = { article: 'A1', description: 'Text', quantity: '2', unit_id: '7', price: '90,00', discount: '', price_source: 'MANUAL', vat_rate: '2500', account: '' };

test('a changed description or VAT rate does not change the signature', () => {
    assert.equal(marginSignature([row]), marginSignature([{ ...row, description: 'Annan text', vat_rate: '600' }]));
});

test('a changed article, quantity, unit, price, discount or source does', () => {
    const base = marginSignature([row]);

    for (const change of [{ article: 'A2' }, { quantity: '3' }, { unit_id: '8' }, { price: '91,00' }, { discount: '5' }, { price_source: 'BASE' }]) {
        assert.notEqual(marginSignature([{ ...row, ...change }]), base);
    }
});

test('only the fields the server reads are sent', () => {
    assert.deepEqual(marginPayload([row]), [{ article: 'A1', quantity: '2', unit_id: '7', price: '90,00', discount: '', price_source: 'MANUAL' }]);
});

test('every source but a typed price is sent as an empty one', () => {
    assert.equal(marginPayload([{ ...row, price_source: 'AGREEMENT' }])[0].price_source, '');
});

test('no rows give an empty list', () => {
    assert.equal(marginSignature(undefined), '[]');
    assert.deepEqual(marginPayload(undefined), []);
});
