import { test } from 'node:test';
import assert from 'node:assert/strict';
import { acquisitionCandidates, accountNumber, categoryFor } from '../../resources/js/asset-candidates.js';

const settings = { ranges: [[1220, 1220], [1250, 1250], [1221, 1227]], threshold: 1_300_000 };
const categories = [
    { value: 'EQUIPMENT', label: 'Inventarier, verktyg och installationer', life: 60, asset: 1220 },
    { value: 'COMPUTERS', label: 'Datorer', life: 36, asset: 1250 },
];

test('reads the account number at the start of the field', () => {
    assert.equal(accountNumber('1220 Inventarier, verktyg och installationer'), 1220);
    assert.equal(accountNumber('Inventarier'), null);
});

test('offers only debits on an acquisition account, and marks those under the threshold', () => {
    const rows = [
        { account: '1220 Inventarier, verktyg och installationer', debit: '40 000', credit: '' },
        { account: '2641', debit: '10 000', credit: '' },
        { account: '1229', debit: '5 000', credit: '' },
        { account: '1250 Datorer', debit: '9 000', credit: '' },
        { account: '1220', debit: '', credit: '40 000' },
        { account: '1930', debit: '', credit: '59 000' },
    ];

    const candidates = acquisitionCandidates(rows, settings, 'voucher');

    assert.deepEqual(candidates.map((item) => [item.index, item.account, item.amount, item.small]), [
        [0, 1220, 4_000_000, false],
        [3, 1250, 900_000, true],
    ]);
});

test('reads supplier invoice rows by their amount', () => {
    const rows = [{ account: '1225', amount: '20 000' }, { account: '1225', amount: '-5 000' }];

    assert.deepEqual(acquisitionCandidates(rows, { ...settings, threshold: null }, 'supplier').map((item) => item.index), [0]);
});

test('guesses the category from the account and its group', () => {
    assert.equal(categoryFor(1250, categories).value, 'COMPUTERS');
    assert.equal(categoryFor(1226, categories).value, 'EQUIPMENT');
    assert.equal(categoryFor(1110, categories).value, 'EQUIPMENT');
});

test('keeps accounts proposed before the BAS 2026 correction in their category', () => {
    const all = [
        { value: 'EQUIPMENT', asset: 1220 },
        { value: 'COMPUTERS', asset: 1224 },
        { value: 'MACHINERY', asset: 1210 },
        { value: 'VEHICLES', asset: 1226 },
    ];

    assert.equal(categoryFor(1240, all).value, 'VEHICLES');
    assert.equal(categoryFor(1250, all).value, 'COMPUTERS');
    assert.equal(categoryFor(1224, all).value, 'COMPUTERS');
    assert.equal(categoryFor(1221, all).value, 'EQUIPMENT');
});
