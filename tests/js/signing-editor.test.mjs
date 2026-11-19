import assert from 'node:assert/strict';
import test from 'node:test';

import { boxFromStyle, boxStyle, clampBox, dragBox, isDrag, keyAction, MIN_SIZE, pointToPage, resizeBox } from '../../resources/js/signing-editor-core.js';

// An A4 page in 1/100 PDF points, shown 595.28 × 841.89 pixels wide (one pixel per point).
const page = { width: 59528, height: 84189 };
const rect = { left: 100, top: 50, width: 595.28, height: 841.89 };

test('a box is drawn as percent of its page from the upper left and read back to the same units', () => {
    const box = { x: 5000, y: 70000, width: 20000, height: 2000 };
    const style = boxStyle(box, page);

    assert.ok(Math.abs(style.left - (5000 / 59528) * 100) < 1e-9);
    assert.ok(Math.abs(style.top - ((84189 - 70000 - 2000) / 84189) * 100) < 1e-9);
    assert.deepEqual(boxFromStyle(style, page), box);
});

test('a point on the screen becomes page units from the lower left', () => {
    assert.deepEqual(pointToPage(100, 50 + 841.89, rect, page), { x: 0, y: 0 });
    assert.deepEqual(pointToPage(100 + 595.28, 50, rect, page), { x: 59528, y: 84189 });
    assert.deepEqual(pointToPage(100 + 595.28 / 2, 50 + 841.89 / 2, rect, page), { x: 29764, y: 42095 });
});

test('a box is kept inside the page and never smaller than the minimum', () => {
    assert.deepEqual(clampBox({ x: -5, y: -5, width: 100, height: 100 }, page), { x: 0, y: 0, width: MIN_SIZE, height: MIN_SIZE });
    assert.deepEqual(clampBox({ x: 59000, y: 84000, width: 5000, height: 5000 }, page), { x: 54528, y: 79189, width: 5000, height: 5000 });
    assert.deepEqual(clampBox({ x: 0, y: 0, width: 999999, height: 999999 }, page), { x: 0, y: 0, width: 59528, height: 84189 });
});

test('dragging moves a box and a drag down means a lower y', () => {
    const box = { x: 5000, y: 50000, width: 20000, height: 2000 };
    const moved = dragBox(box, 10, 20, rect, page, 'move');

    assert.equal(moved.x, 5000 + 1000);
    assert.equal(moved.y, 50000 - 2000);
    assert.equal(moved.width, 20000);
    assert.equal(moved.height, 2000);
});

test('a box cannot be dragged off the page', () => {
    const box = { x: 100, y: 100, width: 20000, height: 2000 };

    assert.deepEqual(dragBox(box, -500, 900, rect, page, 'move'), { x: 0, y: 0, width: 20000, height: 2000 });
    assert.equal(dragBox(box, 5000, 0, rect, page, 'move').x, page.width - 20000);
});

test('resizing pulls the lower right corner and keeps the upper left', () => {
    const box = { x: 5000, y: 50000, width: 20000, height: 2000 };
    const resized = dragBox(box, 10, 10, rect, page, 'resize');

    assert.equal(resized.x, 5000);
    assert.equal(resized.width, 21000);
    assert.equal(resized.height, 3000);
    assert.equal(resized.y + resized.height, box.y + box.height, 'the top edge stays');
    assert.equal(dragBox(box, -5000, -5000, rect, page, 'resize').width, MIN_SIZE);
});

test('resizing past an edge or below the minimum keeps the upper left corner where it is', () => {
    const box = { x: 50000, y: 3000, width: 5000, height: 2000 };
    const top = box.y + box.height;

    // Pulled far past the right and the bottom edge of the page.
    const past = dragBox(box, 5000, 5000, rect, page, 'resize');
    assert.equal(past.x, box.x);
    assert.equal(past.x + past.width, page.width);
    assert.equal(past.y, 0);
    assert.equal(past.y + past.height, top);

    // Pulled far up and left: the minimum, still hanging from the same corner.
    const small = dragBox(box, -5000, -5000, rect, page, 'resize');
    assert.equal(small.x, box.x);
    assert.equal(small.width, MIN_SIZE);
    assert.equal(small.height, MIN_SIZE);
    assert.equal(small.y + small.height, top);
    assert.deepEqual(resizeBox(box, 100, 100, page), small);
});

test('a small movement is a click and a larger one a drag', () => {
    assert.equal(isDrag(1, 1), false);
    assert.equal(isDrag(3, 0), true);
});

test('keys move by one millimetre, ten with shift, resize with alt and remove with delete', () => {
    assert.deepEqual(keyAction('ArrowLeft'), { dx: -1, dy: 0, resize: false });
    assert.deepEqual(keyAction('ArrowDown', { shift: true }), { dx: 0, dy: 10, resize: false });
    assert.deepEqual(keyAction('ArrowRight', { alt: true }), { dx: 1, dy: 0, resize: true });
    assert.deepEqual(keyAction('ArrowUp', { alt: true, shift: true }), { dx: 0, dy: -10, resize: true });
    assert.deepEqual(keyAction('Delete'), { remove: true });
    assert.deepEqual(keyAction('Backspace'), { remove: true });
    assert.equal(keyAction('a'), null);
    assert.equal(keyAction('Tab'), null);
});
