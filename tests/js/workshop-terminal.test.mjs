// The workshop terminal's time arithmetic and wording (ADR-071 §7).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { addMinutes, fill, formatDuration, initials, MAX_MINUTES, minutesSince, totalMinutes } from '../../app/Modules/Manufacturing/resources/js/workshop-core.js';

const strings = { minutes_short: ':min min', hours_short: ':h h', hours_minutes_short: ':h h :min min' };
const t = (key, replace = {}) => fill(strings[key], replace);

test('adds and subtracts minutes without going below zero or above sixteen hours', () => {
    assert.deepEqual(addMinutes({ hours: 0, minutes: 0 }, 75), { hours: 1, minutes: 15 });
    assert.deepEqual(addMinutes({ hours: 1, minutes: 15 }, 60), { hours: 2, minutes: 15 });
    assert.deepEqual(addMinutes({ hours: 1, minutes: 15 }, -5), { hours: 1, minutes: 10 });
    assert.deepEqual(addMinutes({ hours: 0, minutes: 10 }, -60), { hours: 0, minutes: 0 });
    assert.deepEqual(addMinutes({ hours: 15, minutes: 45 }, 60), { hours: 16, minutes: 0 });
    assert.equal(totalMinutes(addMinutes({ hours: 16, minutes: 0 }, 1)), MAX_MINUTES);
});

test('writes a duration in hours and minutes', () => {
    assert.equal(formatDuration(0, t), '0 min');
    assert.equal(formatDuration(45, t), '45 min');
    assert.equal(formatDuration(120, t), '2 h');
    assert.equal(formatDuration(135, t), '2 h 15 min');
});

test('makes initials for a tile without a picture', () => {
    assert.equal(initials('Anna Lind'), 'AL');
    assert.equal(initials('Anna Maria Lind'), 'AL');
    assert.equal(initials('Bo'), 'BO');
    assert.equal(initials('  '), '?');
});

test('fills in placeholders and counts minutes since a time', () => {
    assert.equal(fill('Hej :name, :name', { name: 'Anna' }), 'Hej Anna, Anna');
    const now = Date.parse('2026-11-10T10:00:00Z');
    assert.equal(minutesSince('2026-11-10T08:30:00Z', now), 90);
    assert.equal(minutesSince('2026-11-10T11:00:00Z', now), 0);
});
