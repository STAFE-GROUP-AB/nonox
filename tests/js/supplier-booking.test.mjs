// The same booking as App\Domain\Payables\SupplierInvoiceBooking::register.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { supplierVoucher } from '../../resources/js/supplier-booking.js';

const accounts = {
    names: { 1930: 'Bank', 2440: 'Leverantörsskulder', 2641: 'Debiterad ingående moms', 4010: 'Inköp', 4535: 'Tjänster EU', 2645: 'Beräknad ingående moms', 2614: 'Utgående moms omvänd' },
    vat: { DOMESTIC: { input: 2645, output: { 2500: 2614 } }, EU_SERVICES: { input: 2645, output: { 2500: 2614, 1200: 2624, 600: 2634 } } },
    payables: 2440,
    inputVat: 2641,
};

test('a Swedish invoice: costs and VAT debit, the supplier liability credit', () => {
    const lines = supplierVoucher({
        rows: [{ account: '4010', amount: '1 000,00', vat_rate: '1200' }, { account: '4010', amount: '400', vat_rate: '2500' }],
        total: '1 620,00', vat: '220', treatment: 'DOMESTIC', credit: false, accounts,
    });

    assert.deepEqual(lines.map((line) => [line.account, line.amount]), [[4010, 100000], [4010, 40000], [2641, 22000], [2440, -162000]]);
    assert.equal(lines.reduce((sum, line) => sum + line.amount, 0), 0);
});

test('a credit note books the other way round', () => {
    const lines = supplierVoucher({ rows: [{ account: '4010', amount: '100', vat_rate: '2500' }], total: '125', vat: '25', treatment: 'DOMESTIC', credit: true, accounts });

    assert.deepEqual(lines.map((line) => [line.account, line.amount]), [[4010, -10000], [2641, -2500], [2440, 12500]]);
});

test('reverse charge: the buyer books both input and output VAT', () => {
    const lines = supplierVoucher({ rows: [{ account: '4535', amount: '1000', vat_rate: '2500' }], total: '1000', vat: '0', treatment: 'EU_SERVICES', credit: false, accounts });

    assert.deepEqual(lines.map((line) => [line.account, line.amount]), [[4535, 100000], [2645, 25000], [2614, -25000], [2440, -100000]]);
});

test('nothing while an amount cannot be read', () => {
    assert.equal(supplierVoucher({ rows: [{ account: '4010', amount: 'mycket', vat_rate: '2500' }], total: '125', vat: '25', treatment: 'DOMESTIC', credit: false, accounts }), null);
});
