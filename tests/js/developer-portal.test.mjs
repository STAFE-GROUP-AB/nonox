import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalize, search } from '../../resources/js/developer-portal.js';

const index = [
    { t: 'Bokför en verifikation', s: 'POST /vouchers/{voucher}/post', u: '/utvecklare/referens/verifikationer#postVoucher', k: 'postVoucher Verifikationer' },
    { t: 'Lista verifikationer', s: 'GET /vouchers', u: '/utvecklare/referens/verifikationer#listVouchers', k: 'listVouchers Verifikationer' },
    { t: 'Hämta företaget', s: 'GET /company', u: '/utvecklare/referens/foretaget#getCompany', k: 'getCompany Företaget' },
    { t: 'Idempotens', s: 'Guider', u: '/utvecklare/idempotens', k: 'Idempotency-Key på varje POST' },
];

test('normalizes case and Swedish letters so a search without them still finds', () => {
    assert.equal(normalize('Hämta FÖRETAGET'), 'hamta foretaget');
});

test('finds an operation by its title, path, method or id', () => {
    assert.equal(search(index, 'bokför')[0].u, '/utvecklare/referens/verifikationer#postVoucher');
    assert.equal(search(index, '/company')[0].u, '/utvecklare/referens/foretaget#getCompany');
    assert.equal(search(index, 'listvouchers')[0].k, 'listVouchers Verifikationer');
    assert.equal(search(index, 'foretaget')[0].t, 'Hämta företaget');
});

test('needs every word to match, and ranks a title that starts with the word first', () => {
    assert.deepEqual(search(index, 'get vouchers').map((entry) => entry.t), ['Lista verifikationer']);
    assert.deepEqual(search(index, 'idem post').map((entry) => entry.t), ['Idempotens']);
    assert.deepEqual(search(index, 'v').map((entry) => entry.t).slice(0, 1), ['Bokför en verifikation']);
});

test('finds nothing for an empty query or a word that is nowhere', () => {
    assert.deepEqual(search(index, '   '), []);
    assert.deepEqual(search(index, 'lönebesked'), []);
});
