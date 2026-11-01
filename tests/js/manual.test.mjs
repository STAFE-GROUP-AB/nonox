import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalize, search, highlight, snippet, words } from '../../resources/js/manual.js';

const index = [
    { t: 'Bokför en verifikation', d: 'Så bokför du en händelse för hand.', s: 'Bokföring', u: '/manual/bokforing/bokfor-en-verifikation', k: 'verifikat kontera', h: 'Så här gör du · Vanliga frågor', b: 'Gå till Bokföring och välj Ny verifikation.' },
    { t: 'Momsdeklarationen', d: 'Räkna fram och lämna momsen.', s: 'Moms och EU', u: '/manual/moms/momsdeklarationen', k: 'skatteverket', h: 'Så här gör du', b: 'Rutorna fylls i från bokföringen, även verifikationer i serie M.' },
    { t: 'Ditt lönebesked', d: 'Hitta och läs lönebeskedet på Min sida.', s: 'För anställda', u: '/manual/personal/lonebesked', k: 'lönespecifikation lönespec', h: 'Så här hittar du det', b: 'Öppna Min sida i telefonen.' },
];

test('folds case and Swedish letters, so a search without them still finds', () => {
    assert.equal(normalize('Lönebesked ÅÄÖ'), 'lonebesked aao');
    assert.deepEqual(words('  Moms, och   EU '), ['moms', 'och', 'eu']);
});

test('ranks the title over the search words, the headings and the body', () => {
    assert.equal(search(index, 'verifikation')[0].t, 'Bokför en verifikation');
    assert.equal(search(index, 'lonespec')[0].t, 'Ditt lönebesked');
    assert.equal(search(index, 'skatteverket')[0].t, 'Momsdeklarationen');
    assert.deepEqual(search(index, 'verifikation').map((entry) => entry.t), ['Bokför en verifikation', 'Momsdeklarationen']);
});

test('needs every word to match somewhere', () => {
    assert.deepEqual(search(index, 'min sida telefonen').map((entry) => entry.t), ['Ditt lönebesked']);
    assert.deepEqual(search(index, 'moms lönebesked'), []);
    assert.deepEqual(search(index, '   '), []);
});

test('marks the matching letters in the original text', () => {
    assert.deepEqual(highlight('Ditt lönebesked', ['lon']), [
        { text: 'Ditt ', mark: false },
        { text: 'lön', mark: true },
        { text: 'ebesked', mark: false },
    ]);
    assert.deepEqual(highlight('Moms', ['x']), [{ text: 'Moms', mark: false }]);
});

test('cuts a snippet of the body around the first match', () => {
    const text = `${'a '.repeat(100)}serie M ${'b '.repeat(100)}`;
    const piece = snippet(text, ['serie'], 20);

    assert.ok(piece.startsWith('…'));
    assert.ok(piece.endsWith('…'));
    assert.ok(piece.includes('serie M'));
});
