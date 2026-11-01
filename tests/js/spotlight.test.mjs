import { test } from 'node:test';
import assert from 'node:assert/strict';
import { flatten, isEditable, readLines, segments, sortGroups, step, stepGroup, terms, withoutToken } from '../../resources/js/spotlight-core.js';

const group = (key, order, best, uids) => ({ key, order, best, results: uids.map((uid) => ({ uid, title: uid })) });

test('groups with an exact hit come first, the rest in provider order', () => {
    const sorted = sortGroups([group('pages', 1, 600, ['p']), group('customers', 20, 400, ['c']), group('invoices', 11, 1000, ['i'])]);

    assert.deepEqual(sorted.map((g) => g.key), ['invoices', 'pages', 'customers']);
});

test('arrows move through every result and wrap around', () => {
    const flat = flatten([group('a', 1, 0, ['a1', 'a2']), group('b', 2, 0, ['b1'])]);

    assert.equal(step(flat, 'a1', 1), 'a2');
    assert.equal(step(flat, 'b1', 1), 'a1');
    assert.equal(step(flat, 'a1', -1), 'b1');
    assert.equal(step(flat, null, 1), 'a1');
    assert.equal(step([], null, 1), null);
});

test('Tab jumps to the first result of the next group', () => {
    const groups = [group('a', 1, 0, ['a1', 'a2']), group('b', 2, 0, ['b1', 'b2']), group('c', 3, 0, ['c1'])];

    assert.equal(stepGroup(groups, 'a2', 1), 'b1');
    assert.equal(stepGroup(groups, 'c1', 1), 'a1');
    assert.equal(stepGroup(groups, 'b2', -1), 'a1');
});

test('highlights the typed words without HTML', () => {
    assert.deepEqual(segments('Elverket <b>AB</b>', terms('elverket')), [
        { text: 'Elverket', hit: true },
        { text: ' <b>AB</b>', hit: false },
    ]);
    assert.deepEqual(segments('A47', terms('a')), [{ text: 'A47', hit: false }]);
});

test('removing a chip removes the words it came from', () => {
    assert.equal(withoutToken('faktura Elverket > 10000 kr', '> 10000 kr'), 'faktura Elverket');
    assert.equal(withoutToken('faktura Elverket', 'faktura'), 'Elverket');
});

test('"/" opens the spotlight only when the user is not typing', () => {
    assert.equal(isEditable({ tagName: 'INPUT' }), true);
    assert.equal(isEditable({ tagName: 'DIV', isContentEditable: true }), true);
    assert.equal(isEditable({ tagName: 'BODY' }), false);
    assert.equal(isEditable(null), false);
});

test('reads streamed JSON lines, also when a line is split between chunks', async () => {
    const chunks = ['{"type":"meta"}\n{"type":"gr', 'oup","key":"a"}\n', '{"type":"done"}'];
    const body = new ReadableStream({
        start(controller) {
            chunks.forEach((chunk) => controller.enqueue(new TextEncoder().encode(chunk)));
            controller.close();
        },
    });
    const lines = [];

    await readLines({ body }, (line) => lines.push(line.type));

    assert.deepEqual(lines, ['meta', 'group', 'done']);
});
