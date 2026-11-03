// When NONOX or Skanna runs as an installed app (added to the home screen),
// the zoom that only gets in the way is switched off: a double tap does not
// zoom (touch-action: manipulation), and fields have at least 16px text, the
// size below which iPhone zooms into a field that gets focus. Pinching with
// two fingers still zooms the page, so text can always be enlarged (WCAG 2.2,
// 1.4.4). What is worth a closer look, an invoice or an article picture,
// carries data-zoom and opens in a full-screen viewer that zooms the picture
// itself. In a normal browser tab nothing changes.

const MAX_SCALE = 5;

/** The picture's scale after a pinch, between 1 and MAX_SCALE. */
export function pinchScale(startScale, startDistance, distance) {
    if (!(startDistance > 0) || !(distance > 0)) {
        return startScale;
    }

    return Math.min(MAX_SCALE, Math.max(1, startScale * (distance / startDistance)));
}

export function isInstalled(win = window) {
    return Boolean(win.matchMedia?.('(display-mode: standalone)').matches
        || win.matchMedia?.('(display-mode: fullscreen)').matches
        || win.navigator?.standalone === true);
}

let viewer = null;

function insideViewer(target) {
    return viewer !== null && target instanceof Node && viewer.contains(target);
}

function style() {
    const css = document.createElement('style');
    css.textContent = `
html.is-installed, html.is-installed body { touch-action: manipulation; }
html.is-installed :is(input, select, textarea):not(.text-base, .text-lg, .text-xl, .text-2xl, .text-3xl, .s-scan-input, .s-step-input) { font-size: max(16px, 1rem); }
html.is-installed [data-zoom] { cursor: zoom-in; }
.zoom-viewer { position: fixed; inset: 0; z-index: 2147483000; display: flex; align-items: center; justify-content: center; overflow: hidden; background: rgba(12, 13, 16, .96); touch-action: none; }
.zoom-viewer img { max-width: 100%; max-height: 100%; object-fit: contain; transform-origin: 0 0; user-select: none; -webkit-user-select: none; -webkit-user-drag: none; will-change: transform; }
.zoom-viewer button { position: absolute; top: calc(env(safe-area-inset-top, 0px) + 12px); right: calc(env(safe-area-inset-right, 0px) + 12px); min-height: 48px; padding: 0 18px; border: 0; border-radius: 999px; background: #F4F1EA; color: #15171C; font: 600 16px/1 system-ui, sans-serif; }
`;
    document.head.append(css);
}

function openViewer(source) {
    const src = source.dataset.zoom || source.currentSrc || source.src;

    if (!src) {
        return;
    }

    const returnFocus = document.activeElement;
    const box = document.createElement('div');
    box.className = 'zoom-viewer';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', source.alt || 'Bild');

    const img = document.createElement('img');
    img.src = src;
    img.alt = source.alt || '';
    img.draggable = false;

    const close = document.createElement('button');
    close.type = 'button';
    close.textContent = document.documentElement.lang === 'en' ? 'Close' : 'Stäng';

    box.append(img, close);
    document.body.append(box);

    // A page that is already pinch-zoomed: the viewer covers what is on screen, not the whole page.
    const fitToScreen = () => {
        const screen = window.visualViewport;

        if (!screen || screen.scale <= 1.01) {
            box.style.cssText = '';

            return;
        }

        box.style.cssText = `inset:auto;left:${screen.offsetLeft}px;top:${screen.offsetTop}px;width:${screen.width}px;height:${screen.height}px`;
    };
    fitToScreen();
    window.visualViewport?.addEventListener('resize', fitToScreen);
    window.visualViewport?.addEventListener('scroll', fitToScreen);
    viewer = box;
    close.focus();

    // x/y is where the picture's top-left corner sits, relative to its resting place.
    let scale = 1;
    let x = 0;
    let y = 0;
    const pointers = new Map();
    let gesture = null;
    let lastTap = 0;
    let moved = false;

    // The picture's untransformed place and size on screen.
    const base = () => {
        const rect = img.getBoundingClientRect();

        return { left: rect.left - x, top: rect.top - y, width: rect.width / scale, height: rect.height / scale };
    };

    // Keep the picture on screen: it covers the screen where it is larger, and is centred where it is not.
    const clamp = (b) => {
        const fit = (offset, start, size, room) => (size <= room
            ? (room - size) / 2 - start
            : Math.min(-start, Math.max(room - start - size, offset)));
        x = scale === 1 ? 0 : fit(x, b.left, b.width * scale, box.clientWidth);
        y = scale === 1 ? 0 : fit(y, b.top, b.height * scale, box.clientHeight);
    };

    const apply = () => {
        img.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
    };

    // Zoom so that the picture point under (clientX, clientY) at the start stays under the fingers' point now.
    const zoomTo = (next, b, pointX, pointY, clientX, clientY) => {
        scale = next;
        x = clientX - b.left - pointX * scale;
        y = clientY - b.top - pointY * scale;
        clamp(b);
        apply();
    };

    const startGesture = () => {
        const [a, c] = [...pointers.values()];

        if (c) {
            const b = base();
            const midX = (a.x + c.x) / 2;
            const midY = (a.y + c.y) / 2;
            gesture = { distance: Math.hypot(a.x - c.x, a.y - c.y), scale, b, pointX: (midX - b.left - x) / scale, pointY: (midY - b.top - y) / scale };

            return;
        }

        gesture = { panX: a.x, panY: a.y, x, y, b: base() };
    };

    const onKey = (event) => {
        if (event.key === 'Escape') {
            shut();
        }

        // The keyboard zooms and moves the picture too: + and - zoom, 0 resets, the arrows move.
        const step = { ArrowLeft: [60, 0], ArrowRight: [-60, 0], ArrowUp: [0, 60], ArrowDown: [0, -60] }[event.key];
        const zoom = { '+': 1.5, '=': 1.5, '-': 1 / 1.5, 0: 0 }[event.key];

        if (zoom !== undefined) {
            event.preventDefault();
            const b = base();
            const cx = box.clientWidth / 2;
            const cy = box.clientHeight / 2;
            zoomTo(zoom === 0 ? 1 : pinchScale(scale, 1, zoom), b, (cx - b.left - x) / scale, (cy - b.top - y) / scale, cx, cy);
        }

        if (step !== undefined && scale > 1) {
            event.preventDefault();
            x += step[0];
            y += step[1];
            clamp(base());
            apply();
        }

        // The close button is the only control: Tab stays in the viewer.
        if (event.key === 'Tab') {
            event.preventDefault();
            close.focus();
        }
    };

    const shut = () => {
        window.visualViewport?.removeEventListener('resize', fitToScreen);
        window.visualViewport?.removeEventListener('scroll', fitToScreen);
        box.remove();
        viewer = null;
        document.removeEventListener('keydown', onKey);
        returnFocus?.focus?.();
    };

    box.addEventListener('pointerdown', (event) => {
        if (event.target === close) {
            return;
        }

        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        box.setPointerCapture?.(event.pointerId);
        startGesture();

        if (pointers.size > 1) {
            lastTap = 0;
            moved = true;

            return;
        }

        moved = false;
        const now = Date.now();

        if (now - lastTap < 300) {
            const b = base();
            const next = scale > 1 ? 1 : 2.5;
            zoomTo(next, b, (event.clientX - b.left - x) / scale, (event.clientY - b.top - y) / scale, event.clientX, event.clientY);
            startGesture();
            lastTap = 0;
            moved = true;

            return;
        }

        lastTap = now;
    });

    box.addEventListener('pointermove', (event) => {
        if (!pointers.has(event.pointerId) || gesture === null) {
            return;
        }

        const previous = pointers.get(event.pointerId);
        moved ||= Math.hypot(event.clientX - previous.x, event.clientY - previous.y) > 6;
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        const [a, c] = [...pointers.values()];

        if (c && gesture.distance) {
            const next = pinchScale(gesture.scale, gesture.distance, Math.hypot(a.x - c.x, a.y - c.y));
            zoomTo(next, gesture.b, gesture.pointX, gesture.pointY, (a.x + c.x) / 2, (a.y + c.y) / 2);

            return;
        }

        if (gesture.panX !== undefined && scale > 1) {
            x = gesture.x + (a.x - gesture.panX);
            y = gesture.y + (a.y - gesture.panY);
            clamp(gesture.b);
            apply();
        }
    });

    const release = (event) => {
        pointers.delete(event.pointerId);
        gesture = null;

        if (pointers.size > 0) {
            startGesture();

            return;
        }

        // A tap on the dark background, outside the picture, closes the viewer.
        if (!moved && event.type === 'pointerup' && event.target === box && scale === 1) {
            const rect = img.getBoundingClientRect();
            const outside = event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;

            if (outside) {
                shut();
            }
        }
    };

    box.addEventListener('pointerup', release);
    box.addEventListener('pointercancel', release);
    close.addEventListener('click', shut);
    document.addEventListener('keydown', onKey);
}

/** Call once per page. Does nothing in a normal browser tab. */
export function tameZoomWhenInstalled() {
    if (typeof window === 'undefined' || !isInstalled()) {
        return;
    }

    document.documentElement.classList.add('is-installed');
    style();

    // Inside the viewer the page does not zoom: the viewer zooms the picture with pointer events.
    // A page that is already zoomed keeps its gestures, so it can always be pinched back out.
    const block = (event) => {
        if (insideViewer(event.target) && (window.visualViewport?.scale ?? 1) <= 1.01) {
            event.preventDefault();
        }
    };

    for (const type of ['gesturestart', 'gesturechange']) {
        document.addEventListener(type, block, { passive: false });
    }

    document.addEventListener('touchmove', (event) => {
        if (event.touches.length > 1) {
            block(event);
        }
    }, { passive: false });

    document.addEventListener('click', (event) => {
        const source = event.target instanceof Element ? event.target.closest('img[data-zoom]') : null;

        if (source === null || insideViewer(source)) {
            return;
        }

        event.preventDefault();
        openViewer(source);
    });

    // The pictures are buttons here, so the viewer can be opened from the keyboard too.
    const label = document.documentElement.lang === 'en' ? 'Show larger' : 'Visa större';
    const mark = (root) => {
        root.querySelectorAll?.('img[data-zoom]:not([tabindex])').forEach((img) => {
            img.setAttribute('tabindex', '0');
            img.setAttribute('role', 'button');
            img.setAttribute('aria-label', img.alt ? `${label}: ${img.alt}` : label);
        });
    };

    mark(document);
    new MutationObserver((records) => records.forEach((record) => record.addedNodes.forEach((node) => {
        if (node instanceof Element) {
            mark(node.parentElement ?? node);
        }
    }))).observe(document.body, { childList: true, subtree: true });

    document.addEventListener('keydown', (event) => {
        const source = event.target instanceof Element ? event.target.closest('img[data-zoom]') : null;

        if (source !== null && !insideViewer(source) && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            openViewer(source);
        }
    });
}
