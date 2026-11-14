// The workshop wall screen's logic that needs no page (ADR-071, M7).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clock, codeFromHash, fill, formatDay, isStale, lateText, withoutCode } from '../../app/Modules/Manufacturing/resources/js/wall-core.js';

const strings = {
    day_today: 'i dag', day_tomorrow: 'i morgon', day_yesterday: 'i går',
    after_horizon: 'efter horisonten', late_day: '1 arbetsdag sent', late_days: ':days arbetsdagar sent',
};
const t = (key, replace = {}) => fill(strings[key], replace);

test('reads the pairing code from the fragment, never from the query string', () => {
    assert.equal(codeFromHash('#kod=ABCD-EFGH'), 'ABCD-EFGH');
    assert.equal(codeFromHash('kod=ABCD-EFGH&x=1'), 'ABCD-EFGH');
    assert.equal(codeFromHash(''), '');
    assert.equal(codeFromHash('#x=1'), '');
});

test('removes the pairing code from the address and keeps the rest', () => {
    assert.equal(withoutCode('https://nonox.test/verkstad/storskarm#kod=ABCD-EFGH'), 'https://nonox.test/verkstad/storskarm');
    assert.equal(withoutCode('https://nonox.test/verkstad/storskarm?a=1#kod=ABCD-EFGH&x=2'), 'https://nonox.test/verkstad/storskarm?a=1#x=2');
    assert.equal(withoutCode('https://nonox.test/verkstad/storskarm'), 'https://nonox.test/verkstad/storskarm');
});

test('writes dates near today in words and others with the weekday', () => {
    assert.equal(formatDay('2026-11-16', '2026-11-16', t), 'i dag');
    assert.equal(formatDay('2026-11-17', '2026-11-16', t), 'i morgon');
    assert.equal(formatDay('2026-11-15', '2026-11-16', t), 'i går');
    assert.match(formatDay('2026-11-19', '2026-11-16', t), /^tors?\s+19\s+nov$/);
    // Across the change from summer time does not shift the day.
    assert.equal(formatDay('2026-10-26', '2026-10-25', t), 'i morgon');
});

test('says how late an order is in working days', () => {
    assert.equal(lateText(1, t), '1 arbetsdag sent');
    assert.equal(lateText(3, t), '3 arbetsdagar sent');
    assert.equal(lateText(null, t), 'efter horisonten');
});

test('shows the time of day in Swedish time', () => {
    assert.equal(clock('2026-11-16T08:30:00Z'), '09:30');
    assert.equal(clock('2026-07-01T08:30:00Z'), '10:30');
});

test('marks the board as old after three refreshes without an answer', () => {
    assert.equal(isStale(null, 1_000_000, 30), false);
    assert.equal(isStale(1_000_000, 1_000_000 + 89_000, 30), false);
    assert.equal(isStale(1_000_000, 1_000_000 + 91_000, 30), true);
});
