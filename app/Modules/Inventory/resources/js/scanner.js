// NONOX Lager: Skanna, the phone-first scanning app (ADR-040). Every change
// is stored on the phone first (IndexedDB) as an operation with its own
// UUID, shown as done at once, and sent when there is a network; the server
// runs each UUID once. The reference data (stocked articles, locations,
// stock per location and the open receipts, pick lists and stocktakes) is
// kept on the phone too, so codes can be looked up without coverage.
import {
    applyResults, buildIndex, contentsOf, countRows, decimal, formatQuantity, inSubtree, newOperation,
    parseQuantity, receiptProgress, resolveCode, sendOrder, stockOf, walkingOrder, withPending,
} from './scanner-core.js';
import '../css/scanner.css';
import { tameZoomWhenInstalled } from '../../../../../resources/js/installed-zoom.js';

const config = JSON.parse(document.getElementById('scanner-config').textContent);
tameZoomWhenInstalled();
const app = document.getElementById('scanner');
// Opened from the Skanna home-screen app (its start_url carries ?app=skanna):
// the app stays locked to scanning, with no way out to the full NONOX app.
// The mark lives in sessionStorage, which belongs to that app window only.
const lockedToScanning = (() => {
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;

    try {
        const url = new URL(location.href);

        if (url.searchParams.get('app') === 'skanna') {
            sessionStorage.setItem('nonox-skanna-app', '1');
            url.searchParams.delete('app');
            history.replaceState(history.state, '', url.pathname + url.search + url.hash);
        }

        return standalone && sessionStorage.getItem('nonox-skanna-app') === '1';
    } catch {
        return standalone && new URLSearchParams(location.search).get('app') === 'skanna';
    }
})();
const SYNC_MS = 20000;
const DATA_MS = 5 * 60 * 1000;
const STEP = 1000;

// ---------- storage ----------

let db = null;

const openDb = () => new Promise((resolve, reject) => {
    const request = indexedDB.open('nonox-skanna', 1);
    request.onupgradeneeded = () => {
        request.result.createObjectStore('kv');
        request.result.createObjectStore('queue', { keyPath: 'uuid' });
        request.result.createObjectStore('conflicts', { keyPath: 'uuid' });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

function tx(store, mode, run) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(store, mode);
        const result = run(transaction.objectStore(store));
        transaction.oncomplete = () => resolve(result instanceof IDBRequest ? result.result : result);
        transaction.onerror = () => reject(transaction.error);
    });
}

const kv = {
    get: (key) => tx('kv', 'readonly', (store) => store.get(key)),
    set: (key, value) => tx('kv', 'readwrite', (store) => store.put(value, key)),
    delete: (key) => tx('kv', 'readwrite', (store) => store.delete(key)),
};
const all = (store) => tx(store, 'readonly', (s) => s.getAll());

// ---------- state ----------

const state = {
    raw: null,
    data: null,
    index: buildIndex(null),
    levels: [],
    queue: [],
    conflicts: [],
    online: navigator.onLine,
    session: 'ok',
    syncing: false,
    lastSync: null,
    sound: true,
    keyboard: false,
    screen: 'home',
    work: null,
    confirm: null,
    message: null,
    card: null,
};

function t(key, replace = {}) {
    let text = config.strings[key] ?? key;

    for (const [name, value] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, value);
    }

    return text;
}

function el(tag, attributes = {}, ...children) {
    const node = document.createElement(tag);

    for (const [name, value] of Object.entries(attributes)) {
        if (name.startsWith('on')) {
            node.addEventListener(name.slice(2), value);
        } else if (name === 'value') {
            node.value = value ?? '';
        } else if (value !== false && value !== null && value !== undefined) {
            node.setAttribute(name, value === true ? '' : value);
        }
    }

    for (const child of children.flat()) {
        if (child !== null && child !== undefined && child !== false) {
            node.append(child instanceof Node ? child : document.createTextNode(String(child)));
        }
    }

    return node;
}

const article = (id) => state.index.articles.get(id);
const place = (id) => state.index.locations.get(id);
const placeCode = (id) => (id ? place(id)?.code ?? '?' : t('no_location'));
const qty = (milli, unit = '') => `${formatQuantity(milli)}${unit ? ` ${unit}` : ''}`;
const context = () => state.data?.context ?? null;
const mine = (operation) => context() && operation.company_id === context().company_id && operation.user_id === context().user_id;

// ---------- feedback ----------

let audio = null;

function beep(ok) {
    if (state.sound) {
        try {
            audio ??= new (window.AudioContext || window.webkitAudioContext)();
            const tones = ok ? [[1046, 0, 0.07]] : [[220, 0, 0.16], [196, 0.2, 0.22]];

            for (const [frequency, start, length] of tones) {
                const oscillator = audio.createOscillator();
                const gain = audio.createGain();
                oscillator.type = ok ? 'sine' : 'square';
                oscillator.frequency.value = frequency;
                gain.gain.value = ok ? 0.15 : 0.08;
                oscillator.connect(gain).connect(audio.destination);
                oscillator.start(audio.currentTime + start);
                oscillator.stop(audio.currentTime + start + length);
            }
        } catch {
            // No sound on this device; the flash and the text still show.
        }
    }

    navigator.vibrate?.(ok ? 40 : [120, 60, 120]);
}

let flashTimer = null;

function feedback(ok, text) {
    beep(ok);
    state.message = { ok, text };
    const flash = document.getElementById('s-flash');

    if (flash) {
        flash.className = ok ? 's-flash s-flash-ok' : 's-flash s-flash-error';
        clearTimeout(flashTimer);
        flashTimer = setTimeout(() => { flash.className = 's-flash'; }, ok ? 350 : 900);
    }

    // With the camera open the screen is covered: the answer shows on the camera view too.
    if (scanning) {
        document.getElementById('s-camera-status').textContent = text;
    }

    render();
}

// ---------- network ----------

function xsrf() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request(url, body = null) {
    const response = await fetch(url, {
        method: body === null ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: body === null ? undefined : JSON.stringify(body),
        cache: 'no-store',
        redirect: 'manual',
    });

    // The session has ended (401, 419 or a redirect to the sign-in page): the queue stays on the phone.
    if (response.status === 401 || response.status === 419 || response.type === 'opaqueredirect') {
        state.session = 'expired';
        render();
    }

    if (!response.ok) {
        const error = new Error(`HTTP ${response.status}`);
        error.status = response.status;
        error.body = await response.json().catch(() => null);
        throw error;
    }

    state.session = 'ok';
    state.online = true;

    return response.json();
}

/** The view: the server's data with what still waits on the phone applied. */
function recompute() {
    const waiting = state.raw ? sendOrder(state.queue.filter((operation) => operation.company_id === state.raw.context.company_id && operation.user_id === state.raw.context.user_id)) : [];
    state.data = withPending(state.raw, waiting);
    state.index = buildIndex(state.data);
    state.levels = state.data?.levels ?? [];
}

async function refreshData() {
    try {
        const data = await request(config.urls.data);
        state.raw = data;
        await kv.set('data', data);
        recompute();
        render();

        return true;
    } catch (error) {
        state.online = error.status !== undefined;
        render();

        return false;
    }
}

async function sync() {
    if (state.syncing || !state.data) {
        return;
    }

    const waiting = sendOrder(state.queue.filter(mine)).slice(0, 50);

    if (waiting.length === 0) {
        return;
    }

    state.syncing = true;
    render();

    try {
        const answer = await request(config.urls.operations, {
            company_id: context().company_id,
            operations: waiting.map(({ uuid, kind, payload }) => ({ uuid, kind, payload })),
        });
        const { done, conflicts } = applyResults(waiting, answer.results ?? []);

        await tx('queue', 'readwrite', (store) => [...done, ...conflicts].forEach((operation) => store.delete(operation.uuid)));
        await tx('conflicts', 'readwrite', (store) => conflicts.forEach((operation) => store.put(operation)));
        state.queue = await all('queue');
        state.conflicts = await all('conflicts');
        state.lastSync = new Date().toISOString();

        if (conflicts.length > 0) {
            feedback(false, t('conflicts_new', { count: conflicts.length }));
        }

        await refreshData();
    } catch (error) {
        if (error.status === 409) {
            // Signed in to another company now: the queue waits for the company it was made in.
            await refreshData();
        } else if (error.status === undefined) {
            state.online = false;
        }
    } finally {
        state.syncing = false;
        render();
    }

    if (state.queue.some(mine) && state.online && state.session === 'ok') {
        setTimeout(sync, 500);
    }
}

async function enqueue(kind, payload, done) {
    const operation = newOperation(kind, payload, context(), crypto.randomUUID());
    await tx('queue', 'readwrite', (store) => store.put(operation));
    state.queue.push(operation);
    recompute();
    feedback(true, state.online ? done : `${done} ${t('sent_later')}`);
    sync();

    return operation;
}

// ---------- work in progress (kept, so a reload resumes it) ----------

async function setWork(screen, work) {
    state.screen = screen;
    state.work = work;
    state.confirm = null;
    state.message = null;
    await kv.set('work', { screen, work });
    render();
}

const saveWork = () => kv.set('work', { screen: state.screen, work: state.work });

function home() {
    state.card = null;
    setWork('home', null);
}

// ---------- scanning ----------

function handleScan(raw) {
    const code = String(raw ?? '').trim();

    if (code === '' || !state.data) {
        return;
    }

    const found = resolveCode(code, state.index);
    const handler = {
        home: lookup,
        lookup,
        receive: receiveScan,
        pick: pickScan,
        move: moveScan,
        count: countScan,
        'count-start': countStartScan,
    }[state.screen];

    if (!handler) {
        return;
    }

    if (found.type === null) {
        return feedback(false, t('unknown_code', { code }));
    }

    handler(found);
}

function scanBar() {
    return el('form', {
        class: 's-scanbar',
        onsubmit: (event) => {
            event.preventDefault();
            const input = event.target.elements.code;
            const value = input.value;
            input.value = '';
            handleScan(value);
        },
    },
    el('label', { class: 's-visually-hidden', for: 's-scan' }, t('scan_label')),
    el('input', {
        id: 's-scan', name: 'code', class: 's-scan-input', type: 'text', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false',
        enterkeyhint: 'go', inputmode: state.keyboard ? 'text' : 'none', placeholder: t('scan_placeholder'),
    }),
    el('button', { type: 'button', class: 's-icon-button', 'aria-label': t('camera'), onclick: openCamera }, cameraIcon()),
    el('button', {
        type: 'button', class: 's-icon-button', 'aria-label': t('keyboard'), 'aria-pressed': state.keyboard ? 'true' : 'false',
        onclick: () => { state.keyboard = !state.keyboard; render(); document.getElementById('s-scan')?.focus(); },
    }, keyboardIcon()));
}

const svg = (path) => {
    const node = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    node.setAttribute('viewBox', '0 0 24 24');
    node.setAttribute('aria-hidden', 'true');
    node.innerHTML = path;

    return node;
};
const cameraIcon = () => svg('<path d="M4 8h3l2-3h6l2 3h3v11H4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/>');
const soundIcon = (on) => svg(`<path d="M4 9h4l5-4v14l-5-4H4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>${on ? '<path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>' : '<path d="M17 9l5 6M22 9l-5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'}`);
const keyboardIcon = () => svg('<rect x="2.5" y="6" width="19" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M6 10h1M9 10h1M12 10h1M15 10h1M18 10h0M7 14h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>');

// The camera lives outside the rendered screen, so a render never stops it.
let stream = null;
let scanning = false;
let lastCode = { value: null, at: 0 };

async function openCamera() {
    const overlay = document.getElementById('s-camera');
    const video = document.getElementById('s-video');
    const status = document.getElementById('s-camera-status');
    overlay.hidden = false;
    status.textContent = t('camera_starting');

    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
    } catch {
        status.textContent = t('camera_denied');

        return;
    }

    video.srcObject = stream;
    await video.play().catch(() => {});
    status.textContent = t('camera_hint');
    scanning = true;
    const detect = await detector();
    let busy = false;

    const loop = async () => {
        if (!scanning) {
            return;
        }

        if (!busy && video.readyState >= 2) {
            busy = true;
            const value = await detect(video).catch(() => null);
            busy = false;
            const now = Date.now();

            // The same code held in front of the camera counts once a second and a half.
            if (value && (value !== lastCode.value || now - lastCode.at > 1500)) {
                lastCode = { value, at: now };
                handleScan(value);
            }
        }

        setTimeout(() => requestAnimationFrame(loop), 120);
    };

    loop();
}

function closeCamera() {
    scanning = false;
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
    document.getElementById('s-camera').hidden = true;
    render();
}

/**
 * The browser's own BarcodeDetector where it reads the formats we print
 * (EAN-13, EAN-8, Code 128, QR); elsewhere, such as Safari, the bundled
 * decoder (@zxing/library, Apache-2.0), loaded only then.
 */
async function detector() {
    const formats = ['ean_13', 'ean_8', 'code_128', 'qr_code'];

    if ('BarcodeDetector' in window) {
        const supported = await window.BarcodeDetector.getSupportedFormats().catch(() => []);

        if (formats.every((format) => supported.includes(format))) {
            const native = new window.BarcodeDetector({ formats });

            return async (video) => (await native.detect(video))[0]?.rawValue ?? null;
        }
    }

    const zxing = await import('@zxing/library');
    const hints = new Map([
        [zxing.DecodeHintType.POSSIBLE_FORMATS, [zxing.BarcodeFormat.EAN_13, zxing.BarcodeFormat.EAN_8, zxing.BarcodeFormat.CODE_128, zxing.BarcodeFormat.QR_CODE]],
        [zxing.DecodeHintType.TRY_HARDER, true],
    ]);
    const reader = new zxing.MultiFormatReader();
    reader.setHints(hints);
    const canvas = document.createElement('canvas');
    const context2d = canvas.getContext('2d', { willReadFrequently: true });

    return async (video) => {
        // A centred crop at most 800 px wide keeps each attempt fast on a phone.
        const scale = Math.min(1, 800 / video.videoWidth);
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        context2d.drawImage(video, 0, 0, canvas.width, canvas.height);

        try {
            const bitmap = new zxing.BinaryBitmap(new zxing.HybridBinarizer(new zxing.HTMLCanvasElementLuminanceSource(canvas)));

            return reader.decodeWithState(bitmap).getText();
        } catch {
            return null;
        }
    };
}

// ---------- quantity stepper ----------

function stepper(value, onchange, { min = 0, unit = '', label = t('quantity') } = {}) {
    const set = (next) => onchange(Math.max(min, next));

    return el('div', { class: 's-stepper', role: 'group', 'aria-label': label },
        el('button', { type: 'button', class: 's-step', 'aria-label': t('less'), onclick: () => set(value - STEP), disabled: value - STEP < min }, '−'),
        el('label', { class: 's-step-value' },
            el('span', { class: 's-visually-hidden' }, label),
            el('input', {
                type: 'text', inputmode: 'decimal', autocomplete: 'off', value: formatQuantity(value), class: 's-step-input',
                onfocus: (event) => event.target.select(),
                onchange: (event) => {
                    const parsed = parseQuantity(event.target.value);

                    if (parsed === null || parsed < min) {
                        feedback(false, t('quantity_invalid'));
                    } else {
                        set(parsed);
                    }
                },
            }),
            unit ? el('span', { class: 's-unit' }, unit) : null),
        el('button', { type: 'button', class: 's-step', 'aria-label': t('more'), onclick: () => set(value + STEP) }, '+'));
}

function locationSelect(value, onchange, { allowNone = false } = {}) {
    return el('label', { class: 's-field' },
        el('span', {}, t('location')),
        el('select', { class: 's-select', onchange: (event) => onchange(event.target.value ? Number(event.target.value) : null) },
            allowNone || !value ? el('option', { value: '' }, t('choose_location')) : null,
            (state.data?.locations ?? []).map((location) => el('option', { value: location.id, selected: location.id === value }, location.name ? `${location.code} ${location.name}` : location.code))));
}

function confirmPanel() {
    if (!state.confirm) {
        return null;
    }

    return el('div', { class: 's-confirm', role: 'alertdialog', 'aria-labelledby': 's-confirm-title' },
        el('h2', { id: 's-confirm-title' }, state.confirm.title),
        el('p', {}, state.confirm.text),
        el('div', { class: 's-row' },
            el('button', { type: 'button', class: 's-button s-quiet', onclick: () => { state.confirm = null; render(); } }, t('cancel')),
            el('button', { type: 'button', class: 's-button s-primary', onclick: () => { const run = state.confirm.run; state.confirm = null; run(); } }, state.confirm.button)));
}

function ask(title, text, button, run) {
    state.confirm = { title, text, button, run };
    render();
}

// ---------- Slå upp ----------

async function lookup(found) {
    state.screen = 'lookup';
    state.card = found;
    feedback(true, found.type === 'article' ? found.article.name : found.location.code);

    if (!state.online) {
        return;
    }

    try {
        const fresh = await request(`${config.urls.lookup}?kod=${encodeURIComponent(found.code)}`);

        if (state.card === found) {
            state.card = { ...found, fresh };
            render();
        }
    } catch {
        // The phone's own data is shown.
    }
}

function lookupScreen() {
    const card = state.card;

    if (!card) {
        return el('main', { class: 's-main' }, el('h1', { class: 's-title' }, t('mode_lookup')), el('p', { class: 's-lead' }, t('lookup_hint')));
    }

    if (card.type === 'article') {
        const item = card.article;
        const stock = card.fresh?.stock?.map((row) => ({ location: place(row.location_id) ?? { code: row.code }, quantity: row.quantity })) ?? stockOf(item.id, state.levels, state.index);
        const total = stock.reduce((sum, row) => sum + row.quantity, 0);

        return el('main', { class: 's-main' },
            el('article', { class: 's-card' },
                item.image ? el('img', { class: 's-card-image', src: item.image, alt: '', 'data-zoom': item.image }) : null,
                el('p', { class: 's-eyebrow' }, item.number),
                el('h1', { class: 's-title' }, item.name),
                el('dl', { class: 's-codes' },
                    item.gtin ? [el('dt', {}, 'EAN'), el('dd', {}, item.gtin)] : null,
                    item.scanner_code ? [el('dt', {}, t('scanner_code')), el('dd', {}, item.scanner_code)] : null,
                    item.sku ? [el('dt', {}, 'SKU') , el('dd', {}, item.sku)] : null,
                    el('dt', {}, t('default_location')), el('dd', {}, item.default_location_id ? placeCode(item.default_location_id) : '–')),
                el('h2', { class: 's-subtitle' }, `${t('in_stock')}: ${qty(total, item.unit)}`),
                stock.length === 0 ? el('p', { class: 's-muted' }, t('no_stock')) : el('ul', { class: 's-list' }, stock.map((row) => el('li', { class: 's-list-row' },
                    el('span', { class: 's-code' }, row.location.code), el('span', { class: 's-qty' }, qty(row.quantity, item.unit)))))));
    }

    const location = card.location;
    const contents = card.fresh?.stock?.map((row) => ({ article: article(row.article_id) ?? { number: row.number, name: row.name, unit: row.unit }, quantity: row.quantity })) ?? contentsOf(location.id, state.levels, state.index);

    return el('main', { class: 's-main' },
        el('article', { class: 's-card' },
            el('p', { class: 's-eyebrow' }, t('location')),
            el('h1', { class: 's-title s-code' }, location.code),
            location.name ? el('p', { class: 's-lead' }, location.name) : null,
            el('h2', { class: 's-subtitle' }, t('stored_here')),
            contents.length === 0 ? el('p', { class: 's-muted' }, t('location_empty')) : el('ul', { class: 's-list' }, contents.map((row) => el('li', { class: 's-list-row' },
                el('span', {}, el('strong', {}, row.article.name), el('br'), el('span', { class: 's-muted' }, row.article.number)),
                el('span', { class: 's-qty' }, qty(row.quantity, row.article.unit)))))));
}

// ---------- Ta emot ----------

function startReceive(receiptId) {
    setWork('receive', { receiptId, rows: [], current: null, last: null });
}

function receipt() {
    return state.data?.receipts.find((candidate) => candidate.id === state.work?.receiptId) ?? null;
}

function receiveScan(found) {
    const work = state.work;

    if (found.type === 'location') {
        if (!work.current) {
            return feedback(false, t('scan_article_first'));
        }

        work.current.location_id = found.location.id;
        saveWork();

        return feedback(true, t('location_set', { code: found.location.code }));
    }

    const item = found.article;
    const line = receipt()?.lines.find((candidate) => candidate.article_id === item.id);

    if (receipt() && !line) {
        return feedback(false, t('not_on_receipt', { name: item.name, number: receipt().number }));
    }

    // The article being entered is added first; without a location it stays, and the error says so.
    if (!commitCurrent(false)) {
        return;
    }

    work.current = { article_id: item.id, quantity: STEP, location_id: line?.location_id ?? item.default_location_id ?? null };
    saveWork();
    feedback(true, item.name);
}

/** Adds the article being entered to the rows (same article and place: added together). */
function commitCurrent(withRender = true) {
    const work = state.work;
    const current = work.current;

    if (!current) {
        return true;
    }

    if (!current.location_id) {
        feedback(false, t('choose_location_first'));

        return false;
    }

    const row = work.rows.find((candidate) => candidate.article_id === current.article_id && candidate.location_id === current.location_id);

    if (row) {
        row.quantity += current.quantity;
    } else {
        work.rows.push({ ...current });
    }

    work.last = { ...current };
    work.current = null;
    saveWork();

    if (withRender) {
        feedback(true, t('added', { quantity: qty(current.quantity, article(current.article_id)?.unit), name: article(current.article_id)?.name ?? '' }));
    }

    return true;
}

function sameAgain() {
    state.work.current = { ...state.work.last };
    commitCurrent();
}

function finishReceive() {
    if (!commitCurrent(false)) {
        return;
    }

    const work = state.work;

    if (work.rows.length === 0) {
        return feedback(false, t('nothing_scanned'));
    }

    const units = work.rows.length;

    ask(t('receive_confirm_title'), t('receive_confirm_text', { count: units }), t('receive_button'), async () => {
        await enqueue('receive', {
            receipt_id: work.receiptId,
            lines: work.rows.map((row) => ({ article_id: row.article_id, location_id: row.location_id, quantity: decimal(row.quantity) })),
        }, t('received_done'));
        state.screen = 'home';
        state.work = null;
        await kv.delete('work');
        render();
    });
}

function receiveChooseScreen() {
    const receipts = state.data?.receipts ?? [];

    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, t('mode_receive')),
        el('button', { type: 'button', class: 's-button s-primary s-wide', onclick: () => startReceive(null) }, t('receive_without')),
        el('h2', { class: 's-subtitle' }, t('open_receipts')),
        receipts.length === 0 ? el('p', { class: 's-muted' }, t('no_open_receipts')) : el('ul', { class: 's-choices' }, receipts.map((item) => el('li', {},
            el('button', { type: 'button', class: 's-choice', onclick: () => startReceive(item.id) },
                el('strong', {}, t('receipt_number', { number: item.number })),
                el('span', {}, item.label || t('lines_count', { count: item.lines.length })),
                item.expected_on ? el('span', { class: 's-muted' }, t('expected_on', { date: item.expected_on })) : null)))));
}

function receiveScreen() {
    const work = state.work;
    const current = work.current;
    const doc = receipt();
    const currentArticle = current ? article(current.article_id) : null;

    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, doc ? t('receipt_number', { number: doc.number }) : t('receive_without')),
        current ? el('section', { class: 's-panel', 'aria-label': t('current_article') },
            el('p', { class: 's-eyebrow' }, currentArticle?.number ?? ''),
            el('h2', { class: 's-subtitle' }, currentArticle?.name ?? ''),
            stepper(current.quantity, (value) => { current.quantity = value; saveWork(); render(); }, { min: STEP / 1000, unit: currentArticle?.unit }),
            locationSelect(current.location_id, (value) => { current.location_id = value; saveWork(); render(); }, { allowNone: true }),
            el('p', { class: 's-muted' }, t('scan_shelf_to_change')),
            el('div', { class: 's-row' },
                el('button', { type: 'button', class: 's-button s-quiet', onclick: () => { work.current = null; saveWork(); render(); } }, t('cancel')),
                el('button', { type: 'button', class: 's-button s-primary', onclick: () => commitCurrent() }, t('add'))))
            : el('p', { class: 's-lead' }, t('receive_hint')),
        !current && work.last ? el('button', { type: 'button', class: 's-button s-wide', onclick: sameAgain },
            t('same_again', { quantity: qty(work.last.quantity, article(work.last.article_id)?.unit), name: article(work.last.article_id)?.name ?? '' })) : null,
        doc ? el('ul', { class: 's-list' }, receiptProgress(doc, work.rows).map((line) => {
            const item = article(line.article_id);

            return el('li', { class: line.done ? 's-list-row s-done' : 's-list-row' },
                el('span', {}, el('strong', {}, item?.name ?? '?'), el('br'), el('span', { class: 's-muted' }, `${item?.number ?? ''} · ${placeCode(line.location_id ?? item?.default_location_id)}`)),
                el('span', { class: 's-qty' }, `${formatQuantity(line.received + line.scanned)} / ${formatQuantity(line.ordered)}`, el('br'), el('span', { class: 's-muted' }, t('received_of_expected'))));
        })) : null,
        work.rows.length > 0 ? el('section', {},
            el('h2', { class: 's-subtitle' }, t('scanned_now')),
            el('ul', { class: 's-list' }, work.rows.map((row, position) => el('li', { class: 's-list-row' },
                el('span', {}, el('strong', {}, article(row.article_id)?.name ?? '?'), el('br'), el('span', { class: 's-muted' }, placeCode(row.location_id))),
                el('span', { class: 's-qty' }, qty(row.quantity, article(row.article_id)?.unit)),
                el('button', { type: 'button', class: 's-icon-button', 'aria-label': t('remove_row'), onclick: () => { work.rows.splice(position, 1); saveWork(); render(); } }, '×'))))) : null,
        el('button', { type: 'button', class: 's-button s-primary s-wide s-sticky', onclick: finishReceive, disabled: work.rows.length === 0 && !current }, t('done')));
}

// ---------- Plocka ----------

function startPick(listId) {
    const list = state.data.pick_lists.find((candidate) => candidate.id === listId);
    const lines = walkingOrder(list.lines, state.index);
    const order = lines.map((line) => line.id);

    setWork('pick', { listId, order, position: 0, stage: lines[0]?.location_id ? 'location' : 'article', picked: {}, short: null });
}

function pickList() {
    return state.data?.pick_lists.find((candidate) => candidate.id === state.work?.listId) ?? null;
}

function pickLine() {
    const list = pickList();
    const id = state.work.order[state.work.position];

    return list?.lines.find((line) => line.id === id) ?? null;
}

function pickScan(found) {
    const work = state.work;
    const line = pickLine();

    if (!line) {
        return feedback(false, t('pick_all_done'));
    }

    const wanted = article(line.article_id);

    if (found.type === 'location') {
        if (line.location_id && found.location.id !== line.location_id) {
            return feedback(false, t('wrong_location', { code: found.location.code, wanted: placeCode(line.location_id) }));
        }

        work.stage = 'article';
        saveWork();

        return feedback(true, t('location_ok', { code: found.location.code }));
    }

    if (found.article.id !== line.article_id) {
        return feedback(false, t('wrong_article', { name: found.article.name, wanted: wanted?.name ?? '?' }));
    }

    work.stage = 'quantity';
    work.picked[line.id] = { picked: line.quantity, reason: null };
    saveWork();
    feedback(true, t('article_ok', { name: found.article.name }));
}

function confirmPicked() {
    const work = state.work;
    const line = pickLine();
    const entry = work.picked[line.id] ?? { picked: line.quantity, reason: null };

    if (entry.picked < line.quantity && !entry.reason) {
        work.short = { picked: entry.picked };
        saveWork();

        return render();
    }

    work.picked[line.id] = entry;
    nextPickLine();
}

function nextPickLine() {
    const work = state.work;
    work.position += 1;
    work.stage = 'location';
    work.short = null;
    const next = pickLine();

    if (next && !next.location_id) {
        work.stage = 'article';
    }

    saveWork();
    render();
}

function finishPick() {
    const work = state.work;
    const list = pickList();

    ask(t('pick_confirm_title'), t('pick_confirm_text', { number: list.number }), t('pick_button'), async () => {
        await enqueue('pick', {
            pick_list_id: list.id,
            lines: list.lines.map((line) => {
                const entry = work.picked[line.id] ?? { picked: 0, reason: 'MISSING' };

                return { line_id: line.id, picked: decimal(entry.picked), reason: entry.reason };
            }),
        }, t('picked_done', { number: list.number }));
        state.screen = 'home';
        state.work = null;
        await kv.delete('work');
        render();
    });
}

function pickChooseScreen() {
    const waiting = new Set(state.queue.filter((operation) => operation.kind === 'pick').map((operation) => operation.payload.pick_list_id));
    const lists = (state.data?.pick_lists ?? []).filter((list) => !waiting.has(list.id));

    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, t('mode_pick')),
        lists.length === 0 ? el('p', { class: 's-muted' }, t('no_pick_lists')) : el('ul', { class: 's-choices' }, lists.map((list) => el('li', {},
            el('button', { type: 'button', class: 's-choice', onclick: () => startPick(list.id) },
                el('strong', {}, t('pick_list_number', { number: list.number })),
                el('span', {}, list.label || ''),
                el('span', { class: 's-muted' }, t('lines_count', { count: list.lines.length })))))));
}

function pickScreen() {
    const work = state.work;
    const list = pickList();

    if (!list) {
        return el('main', { class: 's-main' }, el('p', { class: 's-lead' }, t('pick_gone')), el('button', { type: 'button', class: 's-button s-wide', onclick: home }, t('back')));
    }

    const line = pickLine();
    const total = work.order.length;

    if (!line) {
        return el('main', { class: 's-main' },
            el('h1', { class: 's-title' }, t('pick_list_number', { number: list.number })),
            el('p', { class: 's-lead' }, t('pick_all_done')),
            el('ul', { class: 's-list' }, walkingOrder(list.lines, state.index).map((row) => {
                const entry = work.picked[row.id];
                const item = article(row.article_id);
                const short = !entry || entry.picked < row.quantity;

                return el('li', { class: short ? 's-list-row s-short' : 's-list-row s-done' },
                    el('span', {}, el('strong', {}, item?.name ?? '?'), el('br'), el('span', { class: 's-muted' }, placeCode(row.location_id))),
                    el('span', { class: 's-qty' }, `${formatQuantity(entry?.picked ?? 0)} / ${formatQuantity(row.quantity)}`));
            })),
            el('div', { class: 's-row' },
                el('button', { type: 'button', class: 's-button s-quiet', onclick: () => { work.position = 0; work.stage = 'location'; saveWork(); render(); } }, t('pick_again')),
                el('button', { type: 'button', class: 's-button s-primary', onclick: finishPick }, t('done'))));
    }

    const item = article(line.article_id);
    const entry = work.picked[line.id] ?? { picked: line.quantity, reason: null };
    const stages = [['location', t('stage_location')], ['article', t('stage_article')], ['quantity', t('stage_quantity')]];

    return el('main', { class: 's-main' },
        el('p', { class: 's-eyebrow' }, `${t('pick_list_number', { number: list.number })} · ${t('line_of', { line: work.position + 1, total })}`),
        el('section', { class: 's-target' },
            el('p', { class: 's-muted' }, t('go_to')),
            el('p', { class: 's-target-code' }, placeCode(line.location_id)),
            el('p', { class: 's-target-article' }, item?.name ?? '?'),
            el('p', { class: 's-muted' }, `${item?.number ?? ''}${item?.gtin ? ` · ${item.gtin}` : ''}`),
            el('p', { class: 's-target-qty' }, t('take', { quantity: qty(line.quantity, item?.unit) }))),
        el('ol', { class: 's-stages' }, stages.map(([key, label]) => {
            const reached = stages.findIndex(([candidate]) => candidate === work.stage) >= stages.findIndex(([candidate]) => candidate === key);

            return el('li', { class: work.stage === key ? 's-stage s-stage-now' : reached ? 's-stage s-stage-done' : 's-stage' }, label);
        })),
        work.short ? el('section', { class: 's-panel' },
            el('h2', { class: 's-subtitle' }, t('short_title')),
            stepper(work.short.picked, (value) => { work.short.picked = Math.min(value, line.quantity); saveWork(); render(); }, { unit: item?.unit, label: t('picked_quantity') }),
            el('p', {}, t('short_reason')),
            el('div', { class: 's-reasons' }, Object.entries(config.strings.short_reasons ?? {}).map(([key, label]) => el('button', {
                type: 'button', class: 's-button',
                onclick: () => { work.picked[line.id] = { picked: work.short.picked, reason: key }; nextPickLine(); },
            }, label))),
            el('button', { type: 'button', class: 's-button s-quiet s-wide', onclick: () => { work.short = null; saveWork(); render(); } }, t('cancel')))
            : work.stage === 'quantity' ? el('section', { class: 's-panel' },
                stepper(entry.picked, (value) => { work.picked[line.id] = { picked: value, reason: null }; saveWork(); render(); }, { unit: item?.unit, label: t('picked_quantity') }),
                el('div', { class: 's-row' },
                    el('button', { type: 'button', class: 's-button s-quiet', onclick: () => { work.short = { picked: 0 }; saveWork(); render(); } }, t('missing')),
                    el('button', { type: 'button', class: 's-button s-primary', onclick: confirmPicked }, t('picked'))))
                : el('section', { class: 's-panel' },
                    el('p', { class: 's-lead' }, work.stage === 'location' ? t('scan_location_now') : t('scan_article_now')),
                    el('button', { type: 'button', class: 's-button s-quiet s-wide', onclick: () => { work.short = { picked: 0 }; saveWork(); render(); } }, t('missing'))));
}

// ---------- Flytta ----------

function moveScan(found) {
    const work = state.work;

    if (work.stage === 'from') {
        if (found.type !== 'location') {
            return feedback(false, t('scan_from_first'));
        }

        Object.assign(work, { from: found.location.id, stage: 'article' });
        saveWork();

        return feedback(true, t('from_set', { code: found.location.code }));
    }

    if (work.stage === 'article') {
        if (found.type !== 'article') {
            return feedback(false, t('scan_article_now'));
        }

        Object.assign(work, { article: found.article.id, quantity: STEP, stage: 'to' });
        saveWork();
        const here = stockOf(found.article.id, state.levels, state.index).find((row) => row.location.id === work.from)?.quantity ?? 0;

        return feedback(here > 0, here > 0 ? found.article.name : t('none_here', { name: found.article.name, code: placeCode(work.from) }));
    }

    if (found.type !== 'location') {
        return feedback(false, t('scan_to_now'));
    }

    if (found.location.id === work.from) {
        return feedback(false, t('same_location'));
    }

    Object.assign(work, { to: found.location.id, stage: 'confirm' });
    saveWork();
    feedback(true, t('to_set', { code: found.location.code }));
}

async function finishMove() {
    const work = state.work;
    const item = article(work.article);
    await enqueue('move', { article_id: work.article, from_location_id: work.from, to_location_id: work.to, quantity: decimal(work.quantity) },
        t('moved_done', { quantity: qty(work.quantity, item?.unit), name: item?.name ?? '', to: placeCode(work.to) }));
    await setWork('move', { stage: 'from', from: null, article: null, quantity: STEP, to: null });
}

function moveScreen() {
    const work = state.work;
    const item = work.article ? article(work.article) : null;
    const here = item ? stockOf(item.id, state.levels, state.index).find((row) => row.location.id === work.from)?.quantity ?? 0 : 0;
    const steps = [
        ['from', t('move_from'), work.from ? placeCode(work.from) : null],
        ['article', t('move_article'), item ? item.name : null],
        ['to', t('move_to'), work.to ? placeCode(work.to) : null],
    ];

    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, t('mode_move')),
        el('ol', { class: 's-steps' }, steps.map(([key, label, value]) => el('li', { class: work.stage === key ? 's-step-row s-stage-now' : value ? 's-step-row s-stage-done' : 's-step-row' },
            el('span', { class: 's-muted' }, label), el('strong', { class: key === 'article' ? '' : 's-code' }, value ?? '–')))),
        item ? el('section', { class: 's-panel' },
            el('p', { class: 's-muted' }, t('available_here', { quantity: qty(here, item.unit) })),
            stepper(work.quantity, (value) => { work.quantity = value; saveWork(); render(); }, { min: STEP / 1000, unit: item.unit })) : null,
        el('p', { class: 's-lead' }, { from: t('scan_from_first'), article: t('scan_article_now'), to: t('scan_to_now'), confirm: t('move_ready') }[work.stage]),
        el('div', { class: 's-row' },
            el('button', { type: 'button', class: 's-button s-quiet', onclick: () => setWork('move', { stage: 'from', from: null, article: null, quantity: STEP, to: null }) }, t('start_over')),
            el('button', { type: 'button', class: 's-button s-primary', disabled: work.stage !== 'confirm', onclick: finishMove }, t('move_button'))));
}

// ---------- Inventera ----------

function stocktake() {
    return state.data?.counts.find((candidate) => candidate.id === state.work?.countId) ?? null;
}

function startCount(countId) {
    setWork('count', { countId, location: null, counted: {} });
}

async function startNewCount(locationId) {
    if (!state.online) {
        return feedback(false, t('count_needs_network'));
    }

    try {
        const operation = newOperation('count_start', { location_id: locationId }, context(), crypto.randomUUID());
        const answer = await request(config.urls.operations, { company_id: context().company_id, operations: [operation] });
        const result = answer.results?.[0];

        if (result?.status !== 'OK') {
            return feedback(false, result?.message ?? t('failed'));
        }

        state.raw.counts.push(result.data.count);
        await kv.set('data', state.raw);
        recompute();
        feedback(true, t('count_started', { number: result.data.number }));
        startCount(result.data.stock_count_id);
    } catch {
        feedback(false, t('count_needs_network'));
    }
}

function countStartScan(found) {
    if (found.type !== 'location') {
        return feedback(false, t('scan_location_now'));
    }

    startNewCount(found.location.id);
}

function countScan(found) {
    const work = state.work;
    const count = stocktake();
    const root = count?.location_id ? place(count.location_id) : null;

    if (found.type === 'location') {
        if (!inSubtree(found.location, root)) {
            return feedback(false, t('outside_count', { code: found.location.code }));
        }

        work.location = found.location.id;
        saveWork();

        return feedback(true, t('counting_at', { code: found.location.code }));
    }

    if (!work.location) {
        return feedback(false, t('scan_location_first'));
    }

    const key = `${found.article.id}:${work.location}`;
    const row = countRows(count, work.counted).find((candidate) => `${candidate.article_id}:${candidate.location_id}` === key);
    const before = work.counted[key]?.counted ?? row?.counted ?? 0;
    work.counted[key] = { article_id: found.article.id, location_id: work.location, counted: before + STEP, reason: work.counted[key]?.reason ?? null };
    saveWork();
    feedback(true, t('counted_now', { name: found.article.name, quantity: qty(before + STEP, found.article.unit) }));
}

function setCounted(row, value) {
    const key = `${row.article_id}:${row.location_id}`;
    state.work.counted[key] = { article_id: row.article_id, location_id: row.location_id, counted: value, reason: state.work.counted[key]?.reason ?? row.reason };
    saveWork();
    render();
}

function countPayload(complete) {
    return {
        stock_count_id: state.work.countId,
        complete,
        lines: Object.values(state.work.counted).map((entry) => ({ article_id: entry.article_id, location_id: entry.location_id, counted: decimal(entry.counted), reason: entry.reason })),
    };
}

async function saveCount() {
    if (Object.keys(state.work.counted).length === 0) {
        return feedback(false, t('nothing_counted'));
    }

    // Once queued, the counts show through the stocktake itself (withPending), so a later save sends only what is new.
    const payload = countPayload(false);
    state.work.counted = {};
    await saveWork();
    await enqueue('count', payload, t('count_saved'));
}

function finishCount() {
    const count = stocktake();
    const rows = countRows(count, state.work.counted);
    const missing = rows.filter((row) => row.difference !== null && row.difference !== 0 && !row.reason);

    if (missing.length > 0) {
        state.work.needReasons = true;
        saveWork();

        return feedback(false, t('reasons_needed', { count: missing.length }));
    }

    const differences = rows.filter((row) => row.difference).length;

    ask(t('count_confirm_title'), t('count_confirm_text', { count: differences }), t('count_button'), async () => {
        await enqueue('count', countPayload(true), t('count_done', { number: count.number }));
        state.screen = 'home';
        state.work = null;
        await kv.delete('work');
        render();
    });
}

function countChooseScreen() {
    const counts = state.data?.counts ?? [];

    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, t('mode_count')),
        el('button', { type: 'button', class: 's-button s-primary s-wide', onclick: () => setWork('count-start', null) }, t('count_new')),
        el('h2', { class: 's-subtitle' }, t('open_counts')),
        counts.length === 0 ? el('p', { class: 's-muted' }, t('no_counts')) : el('ul', { class: 's-choices' }, counts.map((count) => el('li', {},
            el('button', { type: 'button', class: 's-choice', onclick: () => startCount(count.id) },
                el('strong', {}, t('count_number', { number: count.number })),
                el('span', {}, count.location ?? t('whole_stock')),
                el('span', { class: 's-muted' }, t('lines_count', { count: count.lines.length })))))));
}

function countStartScreen() {
    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, t('count_new')),
        el('p', { class: 's-lead' }, t('count_start_hint')),
        el('button', { type: 'button', class: 's-button s-wide', onclick: () => ask(t('count_new'), t('count_whole_text'), t('count_start_button'), () => startNewCount(null)) }, t('whole_stock')),
        state.online ? null : el('p', { class: 's-warning' }, t('count_needs_network')));
}

function countScreen() {
    const work = state.work;
    const count = stocktake();

    if (!count) {
        return el('main', { class: 's-main' }, el('p', { class: 's-lead' }, t('count_gone')), el('button', { type: 'button', class: 's-button s-wide', onclick: home }, t('back')));
    }

    const rows = countRows(count, work.counted);
    const here = work.location ? rows.filter((row) => row.location_id === work.location) : [];
    const reasons = Object.entries(config.strings.count_reasons ?? {});
    const needing = work.needReasons ? rows.filter((row) => row.difference && !row.reason) : [];
    const row = (item) => {
        const info = article(item.article_id);

        return el('li', { class: 's-count-row' },
            el('div', { class: 's-count-head' },
                el('span', {}, el('strong', {}, info?.name ?? '?'), el('br'), el('span', { class: 's-muted' }, `${info?.number ?? ''} · ${placeCode(item.location_id)}`)),
                el('span', { class: item.difference ? (item.difference < 0 ? 's-diff s-diff-minus' : 's-diff s-diff-plus') : 's-diff' },
                    item.difference === null ? t('not_counted') : item.difference === 0 ? t('no_difference') : `${item.difference > 0 ? '+' : ''}${formatQuantity(item.difference)}`)),
            el('p', { class: 's-muted' }, t('system_quantity', { quantity: qty(item.system, info?.unit) })),
            stepper(item.counted ?? 0, (value) => setCounted(item, value), { unit: info?.unit, label: t('counted_quantity') }),
            item.difference ? el('label', { class: 's-field' }, el('span', {}, t('reason')),
                el('select', {
                    class: 's-select',
                    onchange: (event) => {
                        const key = `${item.article_id}:${item.location_id}`;
                        work.counted[key] = { article_id: item.article_id, location_id: item.location_id, counted: item.counted, reason: event.target.value || null };
                        saveWork();
                        render();
                    },
                }, el('option', { value: '' }, t('choose_reason')), reasons.map(([key, label]) => el('option', { value: key, selected: item.reason === key }, label)))) : null);
    };

    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, t('count_number', { number: count.number })),
        el('p', { class: 's-eyebrow' }, count.location ?? t('whole_stock')),
        el('p', { class: 's-lead' }, work.location ? t('counting_at', { code: placeCode(work.location) }) : t('scan_location_first')),
        needing.length > 0 ? el('section', {}, el('h2', { class: 's-subtitle' }, t('give_reasons')), el('ul', { class: 's-list' }, needing.map(row))) : null,
        work.location ? el('ul', { class: 's-list' }, here.length === 0 ? el('li', { class: 's-muted' }, t('scan_articles_here')) : here.map(row)) : null,
        el('p', { class: 's-muted' }, t('count_progress', { counted: rows.filter((item) => item.counted !== null).length, total: rows.length })),
        el('div', { class: 's-row s-sticky' },
            el('button', { type: 'button', class: 's-button', onclick: saveCount }, t('save')),
            el('button', { type: 'button', class: 's-button s-primary', onclick: finishCount }, t('done'))));
}

// ---------- queue and conflicts ----------

function describe(operation) {
    const payload = operation.payload;

    switch (operation.kind) {
        case 'receive':
            return t('op_receive', { count: payload.lines.length });
        case 'pick':
            return t('op_pick', { id: payload.pick_list_id });
        case 'move':
            return t('op_move', { name: article(payload.article_id)?.name ?? '?', quantity: formatQuantity(parseQuantity(payload.quantity) ?? 0), from: placeCode(payload.from_location_id), to: placeCode(payload.to_location_id) });
        case 'count':
            return payload.complete ? t('op_count_done') : t('op_count');
        default:
            return operation.kind;
    }
}

async function retry(conflict) {
    const { status, message, at, ...operation } = conflict;
    await tx('conflicts', 'readwrite', (store) => store.delete(conflict.uuid));
    state.conflicts = await all('conflicts');
    await enqueue(operation.kind, operation.payload, t('retry_queued'));
}

async function dismiss(conflict) {
    await tx('conflicts', 'readwrite', (store) => store.delete(conflict.uuid));
    state.conflicts = await all('conflicts');
    render();
}

function queueScreen() {
    const waiting = sendOrder(state.queue);
    const foreign = waiting.filter((operation) => !mine(operation));

    return el('main', { class: 's-main' },
        el('h1', { class: 's-title' }, t('queue_title')),
        state.conflicts.length > 0 ? el('section', {},
            el('h2', { class: 's-subtitle' }, t('conflicts_title')),
            el('ul', { class: 's-list' }, state.conflicts.map((conflict) => el('li', { class: 's-conflict' },
                el('strong', {}, describe(conflict)),
                el('p', { class: 's-error-text' }, conflict.message ?? t('failed')),
                el('p', { class: 's-muted' }, t(`advice_${conflict.kind}`)),
                el('div', { class: 's-row' },
                    el('button', { type: 'button', class: 's-button s-quiet', onclick: () => dismiss(conflict) }, t('dismiss')),
                    el('button', { type: 'button', class: 's-button', onclick: () => retry(conflict) }, t('retry'))))))) : null,
        el('h2', { class: 's-subtitle' }, t('pending_title')),
        waiting.length === 0 ? el('p', { class: 's-muted' }, t('nothing_pending')) : el('ul', { class: 's-list' }, waiting.map((operation) => el('li', { class: 's-list-row' },
            el('span', {}, describe(operation), el('br'), el('span', { class: 's-muted' }, new Date(operation.created_at).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' })))))),
        foreign.length > 0 ? el('p', { class: 's-warning' }, t('other_company', { count: foreign.length })) : null,
        el('button', { type: 'button', class: 's-button s-primary s-wide', onclick: () => { sync(); refreshData(); }, disabled: state.syncing }, state.syncing ? t('sending') : t('send_now')));
}

// ---------- home and frame ----------

function homeScreen() {
    const canCount = state.data?.can?.count;
    const tiles = [
        canCount ? ['receive-choose', t('mode_receive'), t('mode_receive_hint'), 'in'] : null,
        canCount ? ['pick-choose', t('mode_pick'), t('mode_pick_hint'), 'out'] : null,
        canCount ? ['move', t('mode_move'), t('mode_move_hint'), 'move'] : null,
        canCount ? ['count-choose', t('mode_count'), t('mode_count_hint'), 'count'] : null,
        ['lookup', t('mode_lookup'), t('mode_lookup_hint'), 'lookup'],
    ].filter(Boolean);

    return el('main', { class: 's-main' },
        state.conflicts.length > 0 ? el('button', { type: 'button', class: 's-banner s-banner-error', onclick: () => setWork('queue', null) }, t('conflicts_banner', { count: state.conflicts.length })) : null,
        el('ul', { class: 's-tiles' }, tiles.map(([screen, title, hint, kind]) => el('li', {},
            el('button', {
                type: 'button', class: `s-tile s-tile-${kind}`,
                onclick: () => setWork(screen, screen === 'move' ? { stage: 'from', from: null, article: null, quantity: STEP, to: null } : null),
            }, el('span', { class: 's-tile-title' }, title), el('span', { class: 's-tile-hint' }, hint))))),
        el('p', { class: 's-muted s-center' }, t('home_hint')));
}

function header() {
    const pending = state.queue.length;
    const status = state.session === 'expired' ? ['s-chip s-chip-error', t('session_expired')]
        : !state.online ? ['s-chip s-chip-offline', t('offline_short')]
            : state.syncing ? ['s-chip', t('sending')]
                : ['s-chip s-chip-online', t('online')];

    return el('header', { class: 's-header' },
        state.screen === 'home'
            ? (lockedToScanning
                ? el('p', { class: 's-back s-brand' }, 'Skanna')
                : el('a', { class: 's-back', href: config.urls.app }, el('span', { 'aria-hidden': 'true' }, '‹'), ' NONOX'))
            : el('button', { type: 'button', class: 's-back', onclick: home }, el('span', { 'aria-hidden': 'true' }, '‹'), ` ${t('back')}`),
        el('div', { class: 's-header-mid' },
            el('p', { class: 's-company' }, state.data?.context?.company ?? 'NONOX'),
            el('p', { class: status[0], role: 'status' }, status[1])),
        el('div', { class: 's-header-end' },
            el('button', {
                type: 'button', class: pending ? 's-badge s-badge-on' : 's-badge', onclick: () => setWork('queue', null),
                'aria-label': pending ? t('pending', { count: pending }) : t('nothing_pending'),
            }, pending ? String(pending) : '✓'),
            el('button', {
                type: 'button', class: 's-icon-button', 'aria-pressed': state.sound ? 'true' : 'false', 'aria-label': t('sound'),
                onclick: async () => { state.sound = !state.sound; await kv.set('sound', state.sound); render(); },
            }, soundIcon(state.sound))));
}

/** "N väntar på att skickas", and the offline note, under the header: always in sight while anything waits. */
function pendingBar() {
    const pending = state.queue.length;

    if (pending === 0 && state.online) {
        return null;
    }

    return el('button', { type: 'button', class: state.online ? 's-pending' : 's-pending s-pending-offline', onclick: () => setWork('queue', null) },
        pending ? el('strong', {}, t('pending', { count: pending })) : null,
        state.online ? null : el('span', {}, t('offline')));
}

function sessionNotice() {
    if (state.session !== 'expired') {
        return null;
    }

    return el('div', { class: 's-banner s-banner-error', role: 'alert' },
        el('span', {}, t('session_expired_text')), ' ',
        el('a', { href: config.urls.login }, t('sign_in')));
}

function messageLine() {
    const message = state.message;

    return el('p', { class: message ? (message.ok ? 's-message s-message-ok' : 's-message s-message-error') : 's-message', role: 'status', 'aria-live': 'assertive' }, message?.text ?? '');
}

function render() {
    // A hand scanner types into whatever has focus: after a tap on a button the scan field takes it back.
    // Only a field the person is typing in (a quantity, a choice) keeps it.
    const focused = document.activeElement;
    const keepScanFocus = !focused || focused.id === 's-scan' || !['INPUT', 'SELECT', 'TEXTAREA'].includes(focused.tagName);
    const typed = document.getElementById('s-scan')?.value ?? '';

    let screen;

    if (!state.data) {
        screen = el('main', { class: 's-main s-center' }, el('h1', { class: 's-title' }, t('title')), el('p', { class: 's-lead' }, state.online ? t('loading') : t('first_start_offline')));
    } else {
        screen = ({
            home: homeScreen,
            lookup: lookupScreen,
            'receive-choose': receiveChooseScreen,
            receive: receiveScreen,
            'pick-choose': pickChooseScreen,
            pick: pickScreen,
            move: moveScreen,
            'count-choose': countChooseScreen,
            'count-start': countStartScreen,
            count: countScreen,
            queue: queueScreen,
        }[state.screen] ?? homeScreen)();
    }

    const scans = ['home', 'lookup', 'receive', 'pick', 'move', 'count', 'count-start'].includes(state.screen) && state.data;

    app.replaceChildren(header(), pendingBar() ?? '', sessionNotice() ?? '', scans ? scanBar() : '', messageLine(), screen, confirmPanel() ?? '');

    const input = document.getElementById('s-scan');

    if (input) {
        input.value = typed;

        if (keepScanFocus && !state.confirm) {
            input.focus({ preventScroll: true });
        }
    }
}

// ---------- start ----------

if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register(config.urls.serviceWorker, { scope: config.urls.scope }).catch(() => {});
}

window.addEventListener('online', () => { state.online = true; refreshData().then(sync); });
window.addEventListener('offline', () => { state.online = false; render(); });
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { refreshData().then(sync); } });
document.getElementById('s-camera-close').addEventListener('click', closeCamera);
document.getElementById('s-desktop-continue')?.addEventListener('click', () => document.body.classList.add('s-desktop-dismissed'));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !document.getElementById('s-camera').hidden) {
        closeCamera();
    }
});

async function start() {
    db = await openDb();
    state.raw = (await kv.get('data')) ?? null;
    state.queue = await all('queue');
    state.conflicts = await all('conflicts');
    state.sound = (await kv.get('sound')) ?? true;
    const saved = await kv.get('work');

    if (saved?.screen && state.raw) {
        state.screen = saved.screen;
        state.work = saved.work;
    }

    recompute();
    render();

    setInterval(sync, SYNC_MS);
    setInterval(refreshData, DATA_MS);

    await refreshData();
    sync();
}

start();
