// Run with: npm test (node --test, no packages needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { addDays, at, dragInterval, drawnInterval, minutesOf, moveToDay, neighbour, snap } from '../../resources/js/planning-math.js';

test('snaps to the time step', () => {
    assert.equal(snap(487, 15), 480);
    assert.equal(snap(488, 15), 495);
    assert.equal(snap(487, 5), 485);
    assert.equal(snap(487, 0), 480);
});

test('adds calendar days across months, years and summer time', () => {
    assert.equal(addDays('2026-03-28', 1), '2026-03-29');
    assert.equal(addDays('2026-10-31', 1), '2026-11-01');
    assert.equal(addDays('2026-12-31', 1), '2027-01-01');
    assert.equal(addDays('2026-03-01', -1), '2026-02-28');
});

test('turns timeline minutes into wall-clock times', () => {
    assert.equal(at('2026-11-02', 0), '2026-11-02 00:00');
    assert.equal(at('2026-11-02', 17 * 60 + 30), '2026-11-02 17:30');
    assert.equal(at('2026-11-02', 26 * 60), '2026-11-03 02:00');
    assert.equal(minutesOf('17:45'), 1065);
});

test('moves a shift to another day with the same clock times', () => {
    assert.deepEqual(moveToDay({ start: '08:00', end: '16:30', endOffset: 0 }, '2026-11-04'), { startsAt: '2026-11-04 08:00', endsAt: '2026-11-04 16:30' });
    assert.deepEqual(moveToDay({ start: '22:00', end: '03:00', endOffset: 1 }, '2026-11-07'), { startsAt: '2026-11-07 22:00', endsAt: '2026-11-08 03:00' });
});

test('drags, resizes and draws on the timeline in steps', () => {
    assert.deepEqual(dragInterval('move', 480, 960, 37, 15), { start: 510, end: 990 });
    assert.deepEqual(dragInterval('move', 480, 960, -600, 15), { start: 0, end: 480 });
    assert.deepEqual(dragInterval('start', 480, 960, 600, 15), { start: 945, end: 960 });
    assert.deepEqual(dragInterval('end', 480, 960, 120, 30), { start: 480, end: 1080 });
    assert.deepEqual(dragInterval('end', 480, 960, -900, 15), { start: 480, end: 495 });
    assert.deepEqual(drawnInterval(1000, 700, 15), { start: 705, end: 1005 });
    assert.deepEqual(drawnInterval(600, 602, 15), { start: 600, end: 615 });
});

test('moves focus between cells with the arrow keys', () => {
    assert.deepEqual(neighbour(0, 0, 'ArrowLeft', 3, 7), { row: 0, column: 0 });
    assert.deepEqual(neighbour(1, 6, 'ArrowRight', 3, 7), { row: 1, column: 6 });
    assert.deepEqual(neighbour(1, 3, 'ArrowDown', 3, 7), { row: 2, column: 3 });
    assert.deepEqual(neighbour(1, 3, 'Enter', 3, 7), { row: 1, column: 3 });
});
