// The scanning app's offline logic (ADR-040). resolveCode follows the same
// rules as ScanCode::resolve, which tests/Feature/Inventory/ScannerTest.php
// checks on the server. Run with: npm test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    adjustLevels, applyResults, buildIndex, changesOf, contentsOf, countRows, decimal, formatQuantity, gtinCandidates, inSubtree,
    newOperation, parseQuantity, receiptProgress, resolveCode, sendOrder, stockOf, walkingOrder, withPending,
} from '../../app/Modules/Inventory/resources/js/scanner-core.js';

const data = {
    context: { company_id: 1, user_id: 7 },
    articles: [
        { id: 10, number: 'V-1', name: 'Kaffekopp', unit: 'st', gtin: '7350000000016', scanner_code: 'KASSA-77', sku: 'SKU-9', default_location_id: 3 },
        { id: 11, number: 'V-2', name: 'Burk', unit: 'st', gtin: '036000291452', scanner_code: null, sku: null, default_location_id: null },
    ],
    locations: [
        { id: 1, code: 'HL', name: 'Huvudlager', sort: 'HL' },
        { id: 2, code: 'HL-A', name: null, sort: 'HL/A' },
        { id: 3, code: 'HL-A-01', name: null, sort: 'HL/A/00000001' },
        { id: 4, code: 'HL-A-02', name: null, sort: 'HL/A/00000002' },
        { id: 5, code: 'HL-B', name: null, sort: 'HL/B' },
    ],
    levels: [[10, 3, 3000], [10, 4, 500]],
    receipts: [{ id: 50, number: 4, lines: [{ id: 500, article_id: 10, location_id: 3, ordered: 5000, received: 1000 }] }],
    pick_lists: [{ id: 60, number: 2, lines: [{ id: 601, article_id: 10, location_id: 4, quantity: 500, picked: null }, { id: 600, article_id: 10, location_id: 3, quantity: 1000, picked: null }] }],
    counts: [{ id: 70, number: 1, location_id: 2, lines: [{ id: 700, article_id: 10, location_id: 3, system: 3000, counted: null, reason: null }] }],
};
const index = buildIndex(data);

test('quantities are thousandths, read the Swedish way', () => {
    assert.equal(parseQuantity('2'), 2000);
    assert.equal(parseQuantity('1,5'), 1500);
    assert.equal(parseQuantity('1 234,25'), 1234250);
    assert.equal(parseQuantity('0.001'), 1);
    assert.equal(parseQuantity('1,2345'), null);
    assert.equal(parseQuantity('två'), null);
    assert.equal(formatQuantity(1500), '1,5');
    assert.equal(formatQuantity(1234000), '1 234');
    assert.equal(formatQuantity(-500), '−0,5');
    assert.equal(decimal(1500), '1.5');
    assert.equal(decimal(2000), '2');
});

test('a GTIN matches with or without one leading zero', () => {
    assert.deepEqual(gtinCandidates('0036000291452'), ['0036000291452', '036000291452', '00036000291452']);
    assert.deepEqual(gtinCandidates('ABC'), []);
});

test('codes resolve by prefix, GTIN, scanner code, SKU, number and location code', () => {
    assert.equal(resolveCode('7350000000016', index).article.id, 10);
    assert.equal(resolveCode('07350000000016', index).article.id, 10);
    assert.equal(resolveCode('0036000291452', index).article.id, 11);
    assert.equal(resolveCode('KASSA-77', index).article.id, 10);
    assert.equal(resolveCode('SKU-9', index).article.id, 10);
    assert.equal(resolveCode('V-2', index).article.id, 11);
    assert.equal(resolveCode('a:V-1', index).article.id, 10);
    assert.equal(resolveCode('L:HL-A-01', index).location.id, 3);
    assert.equal(resolveCode('l:hl-a-02', index).location.id, 4);
    assert.equal(resolveCode(' HL-A-01 ', index).location.id, 3);
    assert.equal(resolveCode('L:V-1', index).type, null);
    assert.equal(resolveCode('OKAND', index).type, null);
    assert.equal(resolveCode('', index).type, null);
});

test('a stocktake of a zone takes the shelves under it, not its neighbours', () => {
    const zone = index.locations.get(2);
    assert.equal(inSubtree(index.locations.get(3), zone), true);
    assert.equal(inSubtree(zone, zone), true);
    assert.equal(inSubtree(index.locations.get(5), zone), false);
    assert.equal(inSubtree(index.locations.get(5), null), true);
});

test('stock per location and per article, in walking order', () => {
    assert.deepEqual(stockOf(10, data.levels, index).map((row) => [row.location.code, row.quantity]), [['HL-A-01', 3000], ['HL-A-02', 500]]);
    assert.deepEqual(contentsOf(3, data.levels, index).map((row) => [row.article.number, row.quantity]), [['V-1', 3000]]);
    assert.deepEqual(walkingOrder(data.pick_lists[0].lines, index).map((line) => line.id), [600, 601]);
});

test('waiting operations show in the stock before the server has them', () => {
    const move = { kind: 'move', payload: { article_id: 10, from_location_id: 3, to_location_id: 5, quantity: '1.5' } };
    const receive = { kind: 'receive', payload: { receipt_id: null, lines: [{ article_id: 11, location_id: 4, quantity: '2' }] } };
    const pick = { kind: 'pick', payload: { pick_list_id: 60, lines: [{ line_id: 601, picked: '0.5', reason: null }, { line_id: 600, picked: '0', reason: 'MISSING' }] } };
    const levels = adjustLevels(data.levels, [move, receive, pick].flatMap((operation) => changesOf(operation, data)));

    assert.deepEqual(levels, [[10, 3, 1500], [10, 5, 1500], [11, 4, 2000]]);
});

test('the view hides finished work and keeps saved counts', () => {
    const view = withPending(data, [
        { kind: 'pick', payload: { pick_list_id: 60, lines: [] } },
        { kind: 'receive', payload: { receipt_id: 50, lines: [{ article_id: 10, location_id: 3, quantity: '2' }] } },
        { kind: 'count', payload: { stock_count_id: 70, complete: false, lines: [{ article_id: 10, location_id: 3, counted: '2', reason: 'THEFT' }, { article_id: 10, location_id: 4, counted: '1', reason: null }] } },
    ]);

    assert.equal(view.pick_lists.length, 0);
    assert.equal(view.receipts[0].lines[0].received, 3000);
    assert.deepEqual(view.counts[0].lines.map((line) => [line.location_id, line.system, line.counted, line.reason]), [[3, 3000, 2000, 'THEFT'], [4, 500, 1000, null]]);
    assert.equal(data.receipts[0].lines[0].received, 1000, 'the server data is not changed');
    assert.equal(withPending(data, [{ kind: 'count', payload: { stock_count_id: 70, complete: true, lines: [] } }]).counts.length, 0);
});

test('receipt progress adds what is scanned now to what was received before', () => {
    const [line] = receiptProgress(data.receipts[0], [{ article_id: 10, location_id: 3, quantity: 4000 }]);
    assert.equal(line.scanned, 4000);
    assert.equal(line.done, true);
});

test('counted rows show the difference, uncounted rows none', () => {
    const rows = countRows(data.counts[0], { '11:4': { article_id: 11, location_id: 4, counted: 1000 } });
    assert.deepEqual(rows.map((row) => [row.article_id, row.difference]), [[10, null], [11, 1000]]);
    assert.deepEqual(countRows(data.counts[0], { '10:3': { article_id: 10, location_id: 3, counted: 2000 } }).map((row) => row.difference), [-1000]);
});

test('server answers: done ones leave the queue, refused ones become conflicts, unanswered stay', () => {
    const queue = ['a', 'b', 'c', 'd'].map((uuid, i) => newOperation('move', {}, { company_id: 1, user_id: 7 }, uuid, new Date(2026, 10, 2, 8, i)));
    const { remaining, done, conflicts } = applyResults(queue, [
        { uuid: 'a', status: 'OK', data: { movements: 2 } },
        { uuid: 'b', status: 'REJECTED', message: 'Det finns bara 0,5 st' },
        { uuid: 'c', status: 'NOT_FOUND', message: 'Finns inte' },
    ]);

    assert.deepEqual(done.map((operation) => operation.uuid), ['a']);
    assert.deepEqual(conflicts.map((operation) => [operation.uuid, operation.message]), [['b', 'Det finns bara 0,5 st'], ['c', 'Finns inte']]);
    assert.deepEqual(remaining.map((operation) => operation.uuid), ['d']);
    assert.equal(queue[0].company_id, 1);
    assert.deepEqual(sendOrder([queue[3], queue[0], queue[2]]).map((operation) => operation.uuid), ['a', 'c', 'd']);
});
