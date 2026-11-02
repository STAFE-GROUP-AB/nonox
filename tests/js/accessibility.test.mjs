// Linking field errors to their fields (resources/js/accessibility.js, docs/tillganglighet.md).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { boundTo, errorSummary, withDescribedBy } from '../../resources/js/accessibility.js';

test('finds the field bound to an error key with any wire:model modifier or its name', () => {
    assert.equal(boundTo([['wire:model', 'email']], 'email'), true);
    assert.equal(boundTo([['wire:model.live.debounce.300ms', 'state.name']], 'state.name'), true);
    assert.equal(boundTo([['name', 'org_number']], 'org_number'), true);
    assert.equal(boundTo([['wire:model', 'email']], 'name'), false);
    assert.equal(boundTo([['id', 'email']], 'email'), false);
    assert.equal(boundTo([['wire:modelx', 'email']], 'email'), false);
});

test('adds an id to aria-describedby once and keeps the ids already there', () => {
    assert.equal(withDescribedBy(null, 'error-email'), 'error-email');
    assert.equal(withDescribedBy('email-hint', 'error-email'), 'email-hint error-email');
    assert.equal(withDescribedBy('email-hint error-email', 'error-email'), 'email-hint error-email');
});

test('says how many errors the form has', () => {
    assert.equal(errorSummary(0, 'Ett fel.', ':count fel.'), '');
    assert.equal(errorSummary(1, 'Ett fel.', ':count fel.'), 'Ett fel.');
    assert.equal(errorSummary(3, 'Ett fel.', ':count fel.'), '3 fel.');
});
