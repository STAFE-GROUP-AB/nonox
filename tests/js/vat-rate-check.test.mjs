// The same rules as App\Domain\Vat\SalesVatRules, while a voucher or an invoice is written (#62).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ratePercent, salesRowVatWarnings, voucherVatWarnings } from '../../resources/js/vat-rate-check.js';

const rules = {
    3001: { rates: [2500], vat: [2610, 2611] },
    3002: { rates: [1200], vat: [2620, 2621] },
    3004: { rates: [0], vat: null },
    3010: { rates: [2500, 1200, 600], vat: [2610, 2611, 2620, 2621, 2630, 2631] },
};

test('warns when a sale at 25 % has no VAT on 2611', () => {
    const rows = [
        { account: '1930 Bank', debit: '1 000', credit: '' },
        { account: '3001 Försäljning inom Sverige, 25 % moms', debit: '', credit: '1 000' },
        { account: '', debit: '', credit: '' },
    ];

    assert.deepEqual(voucherVatWarnings(rows, rules), [{ account: '3001', vat: [2610, 2611] }]);
});

test('is quiet when the voucher books the VAT of the account', () => {
    const rows = [
        { account: '1930', debit: '1 250', credit: '' },
        { account: '3001', debit: '', credit: '1 000' },
        { account: '2611 Utgående moms', debit: '', credit: '250' },
        { account: '3004', debit: '', credit: '0' },
    ];

    assert.deepEqual(voucherVatWarnings(rows, rules), []);
});

test('warns when the VAT is booked at another rate', () => {
    const rows = [
        { account: '1930', debit: '1 120', credit: '' },
        { account: '3001', debit: '', credit: '1 000' },
        { account: '2621', debit: '', credit: '120' },
    ];

    assert.deepEqual(voucherVatWarnings(rows, rules).map((warning) => warning.account), ['3001']);
});

test('warns about an invoice row at 0 % on a 25 % account, from the row or its article', () => {
    const articles = { 10: { account: 3001 }, 20: { account: null } };
    const rows = [
        { article: '', description: 'Konsult', vat_rate: '0', account: '3001' },
        { article: '10', description: 'Vara', vat_rate: '0', account: '' },
        { article: '10', description: 'Vara', vat_rate: '2500', account: '' },
        { article: '20', description: 'Utan konto', vat_rate: '0', account: '' },
        { article: '', description: 'Momsfri', vat_rate: '0', account: '3004' },
        { article: '', description: 'Fel håll', vat_rate: '2500', account: '3004' },
        { article: '', description: '', vat_rate: '0', account: '3001' },
    ];

    assert.deepEqual(salesRowVatWarnings(rows, articles, rules).map((warning) => [warning.line, warning.account, warning.rate]), [
        [1, '3001', 0],
        [2, '3001', 0],
        [6, '3004', 2500],
    ]);
});

test('says nothing when the invoice carries no VAT', () => {
    assert.deepEqual(salesRowVatWarnings([{ description: 'Export', vat_rate: '2500', account: '3004' }], {}, rules, true), []);
});

test('writes a rate as a percentage', () => {
    assert.equal(ratePercent(2500), '25 %');
    assert.equal(ratePercent('600'), '6 %');
});
