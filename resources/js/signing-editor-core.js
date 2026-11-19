/*
 * The geometry of the field editor (ADR-046 amendment, gap 2, package S8),
 * without the DOM so it can be tested in Node. A page is {width, height} in
 * 1/100 PDF points; a box is {x, y, width, height} in the same unit from the
 * page's lower left corner (the crop box, the page as PDF.js shows it), which
 * is what the server stores and validates. On screen a box sits at percent of
 * its page from the upper left. The server clamps every box again (FieldLayout
 * in PHP), so this only keeps the pointer's box sensible while it moves.
 */

/** The smallest a box may be dragged to: 5 points, as SigningFields::MIN_SIZE. */
export const MIN_SIZE = 500;

/** A pointer moves at least this many pixels before a press on a box counts as a drag and not a click. */
export const DRAG_THRESHOLD = 3;

/** A box's place as percent of its page from the upper left, as the CSS of the box draws it. */
export function boxStyle(box, page) {
    return {
        left: (box.x / page.width) * 100,
        top: ((page.height - box.y - box.height) / page.height) * 100,
        width: (box.width / page.width) * 100,
        height: (box.height / page.height) * 100,
    };
}

/** The inverse of boxStyle: percent from the upper left back to a box in page units, rounded to whole units. */
export function boxFromStyle(style, page) {
    const width = Math.round((style.width / 100) * page.width);
    const height = Math.round((style.height / 100) * page.height);

    return {
        x: Math.round((style.left / 100) * page.width),
        y: Math.round(page.height - (style.top / 100) * page.height - height),
        width,
        height,
    };
}

/** A point on screen to page units from the lower left; rect is the page element's bounding rectangle. */
export function pointToPage(clientX, clientY, rect, page) {
    return {
        x: Math.round(((clientX - rect.left) / rect.width) * page.width),
        y: Math.round(page.height - ((clientY - rect.top) / rect.height) * page.height),
    };
}

/** Keeps a box inside its page and at least MIN_SIZE wide and high. */
export function clampBox(box, page) {
    const width = Math.max(MIN_SIZE, Math.min(box.width, page.width));
    const height = Math.max(MIN_SIZE, Math.min(box.height, page.height));

    return {
        x: Math.max(0, Math.min(box.x, page.width - width)),
        y: Math.max(0, Math.min(box.y, page.height - height)),
        width,
        height,
    };
}

/**
 * A box resized to a wanted width and height with its upper left corner fixed: the size is held between the
 * minimum and what the page leaves to the right of and below the corner, so pulling past an edge or below the
 * minimum never moves the opposite edges.
 */
export function resizeBox(box, width, height, page) {
    const top = box.y + box.height;
    const newWidth = Math.max(MIN_SIZE, Math.min(width, page.width - box.x));
    const newHeight = Math.max(MIN_SIZE, Math.min(height, top));

    return clampBox({ x: box.x, y: top - newHeight, width: newWidth, height: newHeight }, page);
}

/**
 * The box after a drag of (dx, dy) pixels. Moving keeps the size; resizing
 * pulls the lower right corner and keeps the upper left corner where it is.
 */
export function dragBox(box, dx, dy, rect, page, mode) {
    const unitsX = (dx / rect.width) * page.width;
    const unitsY = (dy / rect.height) * page.height;

    if (mode === 'resize') {
        return resizeBox(box, Math.round(box.width + unitsX), Math.round(box.height + unitsY), page);
    }

    return clampBox({ x: Math.round(box.x + unitsX), y: Math.round(box.y - unitsY), width: box.width, height: box.height }, page);
}

/** Whether a pointer movement is a drag and not a click. */
export function isDrag(dx, dy) {
    return Math.hypot(dx, dy) >= DRAG_THRESHOLD;
}

/**
 * What a key on a focused box does: arrows move it 1 mm (10 mm with Shift),
 * Alt with an arrow resizes it, Delete and Backspace remove it. dx and dy
 * are millimetres, right and down positive, as the server's nudge takes them.
 */
export function keyAction(key, { shift = false, alt = false } = {}) {
    const step = shift ? 10 : 1;

    switch (key) {
        case 'ArrowLeft':
            return { dx: -step, dy: 0, resize: alt };
        case 'ArrowRight':
            return { dx: step, dy: 0, resize: alt };
        case 'ArrowUp':
            return { dx: 0, dy: -step, resize: alt };
        case 'ArrowDown':
            return { dx: 0, dy: step, resize: alt };
        case 'Delete':
        case 'Backspace':
            return { remove: true };
        default:
            return null;
    }
}
