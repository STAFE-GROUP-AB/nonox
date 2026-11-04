import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clear, createPad, end, hasSignature, inkLength, move, sameName, start, toCanvas, undo } from '../../resources/js/signature-pad-core.js';

const draw = (pad, points) => {
    start(pad, points[0]);
    points.slice(1).forEach((point) => move(pad, point));
    end(pad);

    return pad;
};

test('a stray tap is not a signature, a stroke of some length is', () => {
    const pad = createPad();
    draw(pad, [{ x: 10, y: 10 }]);
    assert.equal(hasSignature(pad), false);

    draw(pad, [{ x: 10, y: 50 }, { x: 60, y: 50 }]);
    assert.equal(inkLength(pad), 50);
    assert.equal(hasSignature(pad), true);
});

test('undo removes the last stroke and clear removes everything', () => {
    const pad = createPad();
    draw(pad, [{ x: 0, y: 0 }, { x: 40, y: 0 }]);
    draw(pad, [{ x: 0, y: 10 }, { x: 40, y: 10 }]);

    undo(pad);
    assert.equal(pad.strokes.length, 1);

    clear(pad);
    assert.equal(pad.strokes.length, 0);
    assert.equal(hasSignature(pad), false);
});

test('points closer than half a pixel are skipped, and move without start does nothing', () => {
    const pad = createPad();
    move(pad, { x: 5, y: 5 });
    assert.equal(pad.strokes.length, 0);

    start(pad, { x: 0, y: 0 });
    move(pad, { x: 0.2, y: 0.2 });
    move(pad, { x: 3, y: 4 });
    assert.equal(pad.strokes[0].length, 2);
});

test('pointer coordinates scale to the canvas and stay inside it', () => {
    const rect = { left: 100, top: 50, width: 300, height: 100 };

    assert.deepEqual(toCanvas(250, 100, rect, 1200, 400), { x: 600, y: 200 });
    assert.deepEqual(toCanvas(50, 500, rect, 1200, 400), { x: 0, y: 400 });
});

test('a typed signature must repeat the name, ignoring case and spaces', () => {
    assert.equal(sameName('  anna   ANDERSSON ', 'Anna Andersson'), true);
    assert.equal(sameName('Åsa Öberg', 'åsa öberg'), true);
    assert.equal(sameName('Anna', 'Anna Andersson'), false);
    assert.equal(sameName('', ''), false);
});
