import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isEmpty, nextRequired, preview } from '../../resources/js/signing-fields-core.js';

const text = (value, required = false) => ({ type: 'text', value, required });
const box = (checked, required = false) => ({ type: 'checkbox', checked, required, value: '1' });
const radio = (name, checked, required = false) => ({ type: 'radio', name, checked, required, value: '1' });

test('a box shows the value, an X when ticked, else the label', () => {
    assert.equal(preview(text(' Kontorshuset AB '), 'Företag'), 'Kontorshuset AB');
    assert.equal(preview(text(''), 'Företag'), 'Företag');
    assert.equal(preview(box(true), 'Godkänner'), 'X');
    assert.equal(preview(box(false), 'Godkänner'), 'Godkänner');
    assert.equal(preview(radio('radio[val]', true), 'Ja'), 'X');
});

test('a radio group is filled when one of its buttons is chosen', () => {
    const yes = radio('radio[val]', false, true);
    const no = radio('radio[val]', true, true);

    assert.equal(isEmpty(yes, [yes, no]), false);
    assert.equal(isEmpty(yes, [yes]), true);
    assert.equal(isEmpty(box(false), []), true);
    assert.equal(isEmpty(text('  '), []), true);
});

test('the next required field is the first empty one after the focused one, wrapping around', () => {
    const first = text('', true);
    const optional = text('');
    const filled = text('12', true);
    const last = box(false, true);
    const controls = [first, optional, filled, last];

    assert.equal(nextRequired(controls, null), first);
    assert.equal(nextRequired(controls, first), last);
    assert.equal(nextRequired(controls, last), first);
    assert.equal(nextRequired([filled, optional], null), null);
});
