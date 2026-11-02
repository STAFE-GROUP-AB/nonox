// When NONOX or Skanna runs as an installed app (added to the home screen),
// the page itself never zooms: no pinch, no double-tap zoom and no automatic
// zoom when a field gets focus, so it behaves like an app. What is worth
// zooming into, an invoice or an article picture, carries data-zoom and opens
// in a full-screen viewer where two fingers zoom the picture only. In a normal
// browser tab nothing changes. A PDF in an iframe is its own document: these
// listeners never see its gestures, so it keeps the zoom the phone gives it.

const MAX_SCALE = 5;

/** The viewport content with page zoom switched off, keeping everything else. */
export function lockedViewport(content) {
    const parts = String(content ?? '')
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '' && !/^(maximum-scale|minimum-scale|user-scalable)\s*=/i.test(part));

    return [...parts, 'maximum-scale=1', 'user-scalable=no'].join(', ');
}

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

function zoomedIn() {
    // A page that did zoom (an iframe's PDF on iPhone) must be able to zoom back out.
    return (window.visualViewport?.scale ?? 1) > 1.01;
}

function insideViewer(target) {
    return viewer !== null && target instanceof Node && viewer.contains(target);
}

function style() {
    const css = document.createElement('style');
    css.textContent = `
html.is-installed, html.is-installed body { touch-action: pan-x pan-y; }
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
    viewer = box;
    close.focus();

    // x/y is where the picture's top-left corner sits, relative to its resting place.
    let scale = 1;
    let x = 0;
    let y = 0;
    const pointers = new Map();
    let gesture = null;
    let lastTap = 0;

    const apply = () => {
        img.style.transform = `translate(${x}px, ${y}px) scale(${scale})`;
    };

    // Zoom around a point on screen, so the spot under the fingers stays put.
    const zoomAt = (next, clientX, clientY) => {
        const rect = img.getBoundingClientRect();
        const originX = rect.left - x;
        const originY = rect.top - y;
        const pointX = (clientX - originX - x) / scale;
        const pointY = (clientY - originY - y) / scale;
        scale = next;
        x = scale === 1 ? 0 : clientX - originX - pointX * scale;
        y = scale === 1 ? 0 : clientY - originY - pointY * scale;
        apply();
    };

    const startGesture = () => {
        const [a, b] = [...pointers.values()];
        gesture = b
            ? { distance: Math.hypot(a.x - b.x, a.y - b.y), scale, midX: (a.x + b.x) / 2, midY: (a.y + b.y) / 2, x, y }
            : { panX: a.x, panY: a.y, x, y };
    };

    const shut = () => {
        box.remove();
        viewer = null;
        document.removeEventListener('keydown', onKey);
        returnFocus?.focus?.();
    };

    const onKey = (event) => {
        if (event.key === 'Escape') {
            shut();
        }
    };

    box.addEventListener('pointerdown', (event) => {
        if (event.target === close) {
            return;
        }

        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        box.setPointerCapture?.(event.pointerId);
        startGesture();

        if (pointers.size === 1) {
            const now = Date.now();

            if (now - lastTap < 300) {
                zoomAt(scale > 1 ? 1 : 2.5, event.clientX, event.clientY);
                startGesture();
            }

            lastTap = now;
        }
    });

    box.addEventListener('pointermove', (event) => {
        if (!pointers.has(event.pointerId) || gesture === null) {
            return;
        }

        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        const [a, b] = [...pointers.values()];

        if (b && gesture.distance) {
            const next = pinchScale(gesture.scale, gesture.distance, Math.hypot(a.x - b.x, a.y - b.y));
            zoomAt(next, (a.x + b.x) / 2, (a.y + b.y) / 2);

            return;
        }

        if (gesture.panX !== undefined && scale > 1) {
            x = gesture.x + (a.x - gesture.panX);
            y = gesture.y + (a.y - gesture.panY);
            apply();
        }
    });

    const release = (event) => {
        pointers.delete(event.pointerId);
        gesture = null;

        if (pointers.size > 0) {
            startGesture();
        }
    };

    box.addEventListener('pointerup', release);
    box.addEventListener('pointercancel', release);
    close.addEventListener('click', shut);
    document.addEventListener('keydown', onKey);
}

/** Call once per page. Does nothing in a normal browser tab. */
export function lockZoomWhenInstalled() {
    if (typeof window === 'undefined' || !isInstalled()) {
        return;
    }

    document.documentElement.classList.add('is-installed');
    style();

    const meta = document.querySelector('meta[name="viewport"]');

    if (meta) {
        meta.setAttribute('content', lockedViewport(meta.getAttribute('content')));
    }

    // iPhone ignores user-scalable=no, so its pinch gestures are stopped here.
    const block = (event) => {
        if (!insideViewer(event.target) && !zoomedIn()) {
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
}
