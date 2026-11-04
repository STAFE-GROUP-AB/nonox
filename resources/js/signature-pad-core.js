/*
 * The signature pad's model (ADR-046), without the DOM so it can be tested in
 * Node: strokes of points in the canvas's own coordinates, undo, clear, and
 * whether enough was drawn to count as a signature. signing.js draws them.
 */

/** A stroke needs this many points, and the whole signature this much ink, before it counts. */
export const MIN_POINTS = 2;
export const MIN_LENGTH = 30;

export function createPad() {
    return { strokes: [], current: null };
}

/** Pointer coordinates (clientX/Y) to canvas coordinates, also when the canvas is scaled by CSS or the device pixel ratio. */
export function toCanvas(clientX, clientY, rect, width, height) {
    const x = ((clientX - rect.left) / (rect.width || 1)) * width;
    const y = ((clientY - rect.top) / (rect.height || 1)) * height;

    return { x: clamp(x, 0, width), y: clamp(y, 0, height) };
}

export function start(pad, point) {
    pad.current = [point];
    pad.strokes.push(pad.current);

    return pad;
}

export function move(pad, point) {
    if (!pad.current) {
        return pad;
    }

    const last = pad.current[pad.current.length - 1];

    // Skip points closer than half a pixel: they add nothing but weight.
    if (Math.hypot(point.x - last.x, point.y - last.y) >= 0.5) {
        pad.current.push(point);
    }

    return pad;
}

export function end(pad) {
    pad.current = null;

    return pad;
}

export function undo(pad) {
    pad.current = null;
    pad.strokes.pop();

    return pad;
}

export function clear(pad) {
    pad.current = null;
    pad.strokes = [];

    return pad;
}

/** The total length of every stroke, in canvas pixels; a dot counts as nothing. */
export function inkLength(pad) {
    let length = 0;

    for (const stroke of pad.strokes) {
        for (let i = 1; i < stroke.length; i++) {
            length += Math.hypot(stroke[i].x - stroke[i - 1].x, stroke[i].y - stroke[i - 1].y);
        }
    }

    return length;
}

/** Enough was drawn to be a signature, not a stray tap. */
export function hasSignature(pad) {
    return pad.strokes.some((stroke) => stroke.length >= MIN_POINTS) && inkLength(pad) >= MIN_LENGTH;
}

/** Typed signatures must repeat the name, ignoring case and extra spaces (the server checks the same). */
export function sameName(typed, name) {
    const normal = (value) => String(value ?? '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('sv-SE');

    return normal(typed) !== '' && normal(typed) === normal(name);
}

function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
}
