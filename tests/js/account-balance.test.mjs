// The same balances as App\Domain\Accounting\AccountBalances, while a voucher is entered.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { accountNumber, balanceBefore, balancesAfterRows } from '../../resources/js/account-balance.js';

const signs = { 1930: 1, 2440: -1, 3001: -1, 6110: 1 };
const balances = { 1930: 1000000, 2440: -500000, 3001: -2000000 };

test('reads the account number the list puts first', () => {
    assert.equal(accountNumber({ account: '1930 Företagskonto' }), '1930');
    assert.equal(accountNumber({ account: ' 6110' }), '6110');
    assert.equal(accountNumber({ account: 'Bank' }), null);
});

test('shows the balance before the voucher on the account\'s normal side', () => {
    assert.equal(balanceBefore({ account: '1930 Bank' }, balances, signs), 1000000);
    assert.equal(balanceBefore({ account: '2440 Leverantörsskulder' }, balances, signs), 500000);
    assert.equal(balanceBefore({ account: '6110' }, balances, signs), 0);
    assert.equal(balanceBefore({ account: '9999' }, balances, signs), null);
    assert.equal(balanceBefore({ account: '1930' }, null, signs), null);
});

test('adds each row and the rows above it on the same account', () => {
    const rows = [
        { account: '6110 Kontorsmateriel', debit: '1 000,00', credit: '' },
        { account: '1930 Bank', debit: '', credit: '600' },
        { account: '1930 Bank', debit: '', credit: '400,00' },
        { account: '3001', debit: '', credit: '250' },
        { account: '', debit: '', credit: '' },
    ];

    assert.deepEqual(balancesAfterRows(rows, balances, signs), [100000, 940000, 900000, 2025000, null]);
});

test('an overdrawn bank account is negative', () => {
    assert.deepEqual(balancesAfterRows([{ account: '1930', debit: '', credit: '20 000' }], balances, signs), [-1000000]);
});

test('an unreadable amount counts as zero', () => {
    assert.deepEqual(balancesAfterRows([{ account: '1930', debit: 'abc', credit: '' }], balances, signs), [1000000]);
});
