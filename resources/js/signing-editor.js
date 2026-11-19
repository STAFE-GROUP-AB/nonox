/*
 * The field editor of the send wizard (ADR-046 amendment, gap 2, package S8),
 * on an internal page: PDF.js (Apache-2.0, bundled by Vite, no CDN) draws the
 * document's pages on canvases; the boxes over them are drawn by Livewire
 * from the wizard's state, and this file adds what a pointer and a keyboard
 * can do with them: drag a box, drag its lower right corner, drop a field
 * type from the palette on a page, and move, resize and remove a focused box
 * with keys. Every change is sent to the wizard, which clamps it into the
 * page; everything here can also be done with the form beside the pages and
 * without JavaScript, so nothing depends on dragging (WCAG 2.2, 2.5.7).
 * The geometry is in signing-editor-core.js.
 *
 * The page's content security policy already allows what PDF.js needs from
 * NONOX itself (worker-src 'self'); nothing is loaded from elsewhere.
 */
import { boxFromStyle, boxStyle, dragBox, isDrag, keyAction, pointToPage } from './signing-editor-core.js';

const PALETTE_TYPE = 'text/x-nonox-field';

/** The Livewire component the element belongs to, or null. */
function wireOf(element) {
    const root = element.closest('[wire\\:id]');
    const component = root && window.Livewire ? window.Livewire.find(root.getAttribute('wire:id')) : null;

    return component ? (component.$wire ?? component) : null;
}

function pageOf(element) {
    const page = element.closest('[data-editor-page]');

    return page ? { element: page, number: Number(page.dataset.editorPage), size: { width: Number(page.dataset.pageWidth), height: Number(page.dataset.pageHeight) } } : null;
}

/** The box as the element draws it, in page units. */
function boxOf(element, size) {
    return boxFromStyle({ left: parseFloat(element.style.left), top: parseFloat(element.style.top), width: parseFloat(element.style.width), height: parseFloat(element.style.height) }, size);
}

/* ---------- PDF.js: the pages ---------- */

let loading = null;
const documents = new Map();

function loadPdfJs() {
    loading ??= Promise.all([import('pdfjs-dist/legacy/build/pdf.mjs'), import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url')]).then(([pdfjs, worker]) => {
        pdfjs.GlobalWorkerOptions.workerSrc = worker.default;

        return pdfjs;
    });

    return loading;
}

function loadDocument(url) {
    if (!documents.has(url)) {
        const promise = loadPdfJs().then((pdfjs) => pdfjs.getDocument({ url, withCredentials: true, isEvalSupported: false, enableScripting: false, useSystemFonts: true }).promise);
        promise.catch(() => documents.delete(url));
        documents.set(url, promise);
    }

    return documents.get(url);
}

async function renderPage(host, url, number) {
    host.dataset.state = 'loading';

    try {
        const pdf = await loadDocument(url);
        const page = await pdf.getPage(number);
        const base = page.getViewport({ scale: 1 });
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        const cssWidth = Math.max(host.clientWidth, 200);
        const viewport = page.getViewport({ scale: (cssWidth / base.width) * ratio });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        canvas.className = 'block h-full w-full';
        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        host.replaceChildren(canvas);
        host.dataset.state = 'done';
    } catch {
        host.dataset.state = 'failed';
        host.replaceChildren();
        notice(host.closest('[data-signing-editor]'));
    }
}

/** Says once, politely, that the preview failed: the boxes and the form still work. */
function notice(root) {
    if (!root || root.querySelector('[data-preview-failed]')) {
        return;
    }

    const note = document.createElement('p');
    note.dataset.previewFailed = '';
    note.setAttribute('role', 'status');
    note.className = 'mb-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900';
    note.textContent = root.dataset.failedText ?? '';
    root.prepend(note);
}

const observer = 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (!entry.isIntersecting) {
                continue;
            }

            const host = entry.target;
            observer.unobserve(host);
            renderPage(host, host.closest('[data-signing-editor]')?.dataset.pdfUrl, Number(host.closest('[data-editor-page]')?.dataset.editorPage));
        }
    }, { rootMargin: '600px 0px' })
    : null;

/** Hooks up every page host that has appeared and not yet been given a canvas. */
function scan() {
    for (const host of document.querySelectorAll('[data-signing-editor] [data-pdf-host]:not([data-state])')) {
        host.dataset.state = 'waiting';

        if (observer) {
            observer.observe(host);
        } else {
            renderPage(host, host.closest('[data-signing-editor]').dataset.pdfUrl, Number(host.closest('[data-editor-page]').dataset.editorPage));
        }
    }
}

/* ---------- pointer: drag, resize, drop ---------- */

let drag = null;
let cancelClick = null;

/** Removes the one-off click canceller of the last drag, if it is still waiting. */
function dropClickCanceller() {
    if (cancelClick) {
        window.removeEventListener('click', cancelClick.handler, true);
        clearTimeout(cancelClick.timer);
        cancelClick = null;
    }
}

document.addEventListener('pointerdown', (event) => {
    dropClickCanceller();

    const element = event.target instanceof Element ? event.target.closest('[data-field-box]') : null;

    if (!element || event.button !== 0 || !event.isPrimary) {
        return;
    }

    const page = pageOf(element);
    const wire = wireOf(element);

    if (!page || !wire) {
        return;
    }

    drag = {
        element,
        wire,
        page,
        index: Number(element.dataset.fieldBox),
        mode: event.target.closest('[data-resize-handle]') ? 'resize' : 'move',
        startX: event.clientX,
        startY: event.clientY,
        origin: boxOf(element, page.size),
        box: null,
        moved: false,
    };
    element.setPointerCapture(event.pointerId);
});

document.addEventListener('pointermove', (event) => {
    if (!drag || event.target !== drag.element) {
        return;
    }

    const dx = event.clientX - drag.startX;
    const dy = event.clientY - drag.startY;

    if (!drag.moved && !isDrag(dx, dy)) {
        return;
    }

    drag.moved = true;
    drag.box = dragBox(drag.origin, dx, dy, drag.page.element.getBoundingClientRect(), drag.page.size, drag.mode);

    const style = boxStyle(drag.box, drag.page.size);
    drag.element.style.left = `${style.left}%`;
    drag.element.style.top = `${style.top}%`;
    drag.element.style.width = `${style.width}%`;
    drag.element.style.height = `${style.height}%`;
});

function finishDrag(event) {
    if (!drag || event.target !== drag.element) {
        return;
    }

    const finished = drag;
    drag = null;

    if (finished.moved && finished.box && event.type === 'pointerup') {
        // The press that moved the box must not also select it a second time.
        const handler = (click) => {
            click.stopImmediatePropagation();
            dropClickCanceller();
        };
        cancelClick = { handler, timer: setTimeout(dropClickCanceller, 100) };
        window.addEventListener('click', handler, true);
        finished.wire.moveField(finished.index, finished.box.x, finished.box.y, finished.box.width, finished.box.height);
    }
}

document.addEventListener('pointerup', finishDrag);
document.addEventListener('pointercancel', finishDrag);

document.addEventListener('dragstart', (event) => {
    const chip = event.target instanceof Element ? event.target.closest('[data-palette-type]') : null;

    if (chip && event.dataTransfer) {
        event.dataTransfer.setData(PALETTE_TYPE, chip.dataset.paletteType);
        event.dataTransfer.effectAllowed = 'copy';
    }
});

document.addEventListener('dragover', (event) => {
    const page = event.target instanceof Element ? event.target.closest('[data-editor-page]:not([data-rotated])') : null;

    if (page && event.dataTransfer?.types.includes(PALETTE_TYPE)) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    }
});

document.addEventListener('drop', (event) => {
    const element = event.target instanceof Element ? event.target.closest('[data-editor-page]:not([data-rotated])') : null;
    const type = event.dataTransfer?.getData(PALETTE_TYPE);

    if (!element || !type) {
        return;
    }

    event.preventDefault();

    const page = pageOf(element);
    const wire = wireOf(element);
    const point = pointToPage(event.clientX, event.clientY, element.getBoundingClientRect(), page.size);

    wire?.addFieldAt(type, page.number, point.x, point.y);
});

/* ---------- keyboard: move, resize, remove ---------- */

document.addEventListener('keydown', (event) => {
    const element = event.target instanceof Element ? event.target.closest('[data-field-box]') : null;

    if (!element || event.target !== element || event.ctrlKey || event.metaKey) {
        return;
    }

    const action = keyAction(event.key, { shift: event.shiftKey, alt: event.altKey });
    const wire = wireOf(element);

    if (!action || !wire) {
        return;
    }

    event.preventDefault();

    if (action.remove) {
        Promise.resolve(wire.removeField(Number(element.dataset.fieldBox))).then(() => {
            document.querySelector('[data-testid="signing-editor-add"] select')?.focus();
        });

        return;
    }

    wire.nudge(Number(element.dataset.fieldBox), action.dx, action.dy, action.resize);
});

/* ---------- start ---------- */

new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
scan();
