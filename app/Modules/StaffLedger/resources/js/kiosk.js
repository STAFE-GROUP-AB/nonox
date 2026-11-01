// NONOX Personalliggare: the kiosk (ADR-022). An installable page that keeps
// working without the network: the roster and the device token live in
// IndexedDB, every check-in is stored there first (with the device's time,
// a sequence number and the device's hash chain) and shown as done at once,
// and a background loop sends the queue to the server in order.
import {
    applyAck, createEvent, csv, MAX_PIN_ATTEMPTS, nextDirection, pinLockState, pinMatches,
    presentSince, registerPinFailure, sendOrder, sha256, zip,
} from './kiosk-core.js';
import '../css/kiosk.css';

const config = JSON.parse(document.getElementById('kiosk-config').textContent);
const app = document.getElementById('kiosk');
const SYNC_MS = 15000;
const ROSTER_MS = 5 * 60 * 1000;
const PRESS_MS = 180;
const RESULT_MS = 3000;
const IDLE_MS = 30000;

// ---------- storage ----------

let db = null;

const openDb = () => new Promise((resolve, reject) => {
    const request = indexedDB.open('nonox-personalliggare', 1);
    request.onupgradeneeded = () => {
        request.result.createObjectStore('kv');
        request.result.createObjectStore('queue', { keyPath: 'sequence' });
        request.result.createObjectStore('rejected', { keyPath: 'sequence' });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

function tx(store, mode, run) {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(store, mode);
        const result = run(transaction.objectStore(store));
        // A request's own result, also when it is undefined (a missing key): never the request itself.
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

// Personal numbers are kept encrypted with a key the page can use but never read out.
async function sensitiveKey() {
    let key = await kv.get('sensitive_key');

    if (!key) {
        key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
        await kv.set('sensitive_key', key);
    }

    return key;
}

async function saveSensitive(data) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const cipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, await sensitiveKey(), new TextEncoder().encode(JSON.stringify(data)));
    await kv.set('sensitive', { iv, cipher });
}

async function loadSensitive() {
    const stored = await kv.get('sensitive');

    if (!stored) {
        return { people: [], shifts: [] };
    }

    const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: stored.iv }, await sensitiveKey(), stored.cipher);

    return JSON.parse(new TextDecoder().decode(plain));
}

// ---------- state ----------

const state = {
    token: null,
    roster: null,
    lastSync: null,
    queue: [],
    language: 'sv',
    online: navigator.onLine,
    screen: 'home',
    person: null,
    pin: '',
    message: null,
    timer: null,
    pressedKey: null,
    pressTimer: null,
};

function t(key, replace = {}, language = state.language) {
    const strings = config.strings[language] ?? config.strings.sv;
    let text = strings[key] ?? config.strings.sv[key] ?? key;

    for (const [name, value] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, value);
    }

    return text;
}

function setLanguage(language) {
    document.documentElement.lang = language;
    document.documentElement.dir = config.rtl.includes(language) ? 'rtl' : 'ltr';
}

function time(iso) {
    return iso ? new Date(iso).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' }) : '';
}

function dateTime(iso) {
    return iso ? new Date(iso).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' }) : '';
}

function el(tag, attributes = {}, ...children) {
    const node = document.createElement(tag);

    for (const [name, value] of Object.entries(attributes)) {
        if (name.startsWith('on')) {
            node.addEventListener(name.slice(2), value);
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

// ---------- network ----------

async function api(path, body = null) {
    const response = await fetch(config.urls[path], {
        method: body === null ? 'GET' : 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${state.token}` },
        body: body === null ? undefined : JSON.stringify(body),
        cache: 'no-store',
    });

    if (response.status === 401) {
        state.revoked = true;
        render();
    }

    if (!response.ok) {
        const error = new Error(`HTTP ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return response.json();
}

/** True when a fresh roster was downloaded. */
async function refreshRoster() {
    if (!state.token) {
        return false;
    }

    try {
        const roster = await api('roster');
        const { sensitive, ...open } = roster;
        await saveSensitive(sensitive);
        await kv.set('roster', open);
        state.roster = open;
        state.online = true;
        state.revoked = false;
    } catch (error) {
        console.warn('NONOX kiosk: the roster could not be refreshed.', error);
        // A blocked kiosk is online; it only needs a new code.
        state.online = error.status === 401 ? true : error.status === undefined ? false : state.online;

        return false;
    } finally {
        if (state.screen === 'home') {
            render();
        }
    }

    return true;
}

let syncing = false;

async function sync() {
    if (syncing || !state.token) {
        return;
    }

    syncing = true;

    try {
        const queue = sendOrder(await all('queue')).slice(0, 100);
        const logs = (await kv.get('logs')) ?? [];

        if (queue.length === 0 && logs.length === 0) {
            return;
        }

        const ack = await api('events', { sent_at: new Date().toISOString(), events: queue, logs });
        const { remaining, rejected } = applyAck(queue, ack);
        const keep = new Set(remaining.map((event) => event.sequence));

        await tx('queue', 'readwrite', (store) => queue.filter((event) => !keep.has(event.sequence)).forEach((event) => store.delete(event.sequence)));
        await tx('rejected', 'readwrite', (store) => rejected.forEach((event) => store.put(event)));
        await kv.set('logs', []);

        state.queue = await all('queue');
        state.lastSync = new Date().toISOString();
        await kv.set('last_sync', state.lastSync);
        state.online = true;

        if (state.queue.length > 0) {
            setTimeout(sync, 500);
        }
    } catch {
        state.online = false;
    } finally {
        syncing = false;

        if (state.screen === 'home') {
            render();
        }
    }
}

// ---------- actions ----------

async function record(person, method) {
    const direction = nextDirection(person, state.queue);
    const lastSequence = Math.max((await kv.get('last_sequence')) ?? 0, state.roster.device.last_sequence ?? 0);
    const lastHash = (await kv.get('last_hash')) ?? state.roster.device.last_client_hash ?? null;
    const event = await createEvent({
        uuid: crypto.randomUUID(),
        personId: person.id,
        locationId: state.roster.location.id,
        direction,
        method,
        now: new Date(),
        lastSequence,
        lastHash,
    });

    // Stored on the device first: the person is done now, whatever the network does.
    await tx('queue', 'readwrite', (store) => store.put(event));
    await kv.set('last_sequence', event.sequence);
    await kv.set('last_hash', event.client_hash);
    state.queue.push(event);

    show('result', { person, direction, at: event.occurred_at });
    sync();
}

function show(screen, extra = {}) {
    clearTimeout(state.timer);
    Object.assign(state, { screen, pin: '', message: null, ...extra });

    if (screen === 'result') {
        state.timer = setTimeout(() => show('home', { person: null }), RESULT_MS);
    } else if (screen !== 'home') {
        state.timer = setTimeout(() => show('home', { person: null }), screen === 'control' ? IDLE_MS * 10 : IDLE_MS);
    }

    render();
}

/**
 * Marks a key as pressed. Every press redraws the keypad, which would end a
 * CSS :active state at once, so the new key carries the mark itself. It is
 * cleared in place (not by a redraw), so the next tap is never lost.
 */
function flash(label) {
    state.pressedKey = label;
    clearTimeout(state.pressTimer);
    state.pressTimer = setTimeout(() => {
        state.pressedKey = null;
        document.querySelectorAll('.k-pressed').forEach((key) => key.classList.remove('k-pressed'));
    }, PRESS_MS);
}

async function pressDigit(digit) {
    if (state.pin.length >= 4) {
        return;
    }

    flash(digit);
    state.pin += digit;
    state.message = null;
    render();

    if (state.pin.length === 4) {
        await (state.screen === 'manager-pin' ? checkManagerPin() : checkPersonPin());
    }
}

async function checkPersonPin() {
    const person = state.person;
    const attempts = (await kv.get(`attempts:${person.id}`)) ?? null;

    if (pinLockState(attempts, Date.now()).locked) {
        state.pin = '';
        state.message = t('locked', { minutes: 5 });

        return render();
    }

    if (await pinMatches(state.pin, person, state.roster.iterations)) {
        await kv.delete(`attempts:${person.id}`);

        return record(person, 'KIOSK_PIN');
    }

    // The code may have been changed since the roster was downloaded: check once more against a fresh one.
    if (state.online && await refreshRoster()) {
        const fresh = state.roster.people.find((candidate) => candidate.id === person.id);

        if (fresh && await pinMatches(state.pin, fresh, state.roster.iterations)) {
            await kv.delete(`attempts:${person.id}`);
            state.person = fresh;

            return record(fresh, 'KIOSK_PIN');
        }
    }

    const next = registerPinFailure(attempts, Date.now());
    await kv.set(`attempts:${person.id}`, next);
    state.pin = '';
    state.message = next.count >= MAX_PIN_ATTEMPTS ? t('locked', { minutes: 5 }) : t('wrong_pin');
    render();
}

async function checkManagerPin() {
    const pin = state.pin;

    try {
        const answer = await api('controlVisit', { pin });

        if (answer.ok) {
            const { sensitive, ...open } = answer.roster;
            await saveSensitive(sensitive);
            await kv.set('roster', open);
            state.roster = open;

            return openControl(true);
        }

        state.pin = '';
        state.message = answer.locked ? t('locked', { minutes: 15 }) : t('wrong_pin');

        return render();
    } catch (error) {
        // The server answered: it decides (429 is its lockout). Only without the network is the PIN checked on the device.
        if (error.status) {
            state.pin = '';
            state.message = error.status === 429 ? t('locked', { minutes: 15 }) : t('wrong_pin');

            return render();
        }
    }

    const managers = state.roster.people.filter((person) => person.is_manager);
    const lock = (await kv.get('attempts:manager')) ?? null;

    if (pinLockState(lock, Date.now()).locked) {
        state.pin = '';
        state.message = t('locked', { minutes: 5 });

        return render();
    }

    for (const manager of managers) {
        if (await pinMatches(pin, manager, state.roster.iterations)) {
            await kv.delete('attempts:manager');
            const logs = (await kv.get('logs')) ?? [];
            logs.push({ type: 'control_visit_opened', person_id: manager.id, at: new Date().toISOString() });
            await kv.set('logs', logs);

            return openControl(false);
        }
    }

    await kv.set('attempts:manager', registerPinFailure(lock, Date.now()));
    state.pin = '';
    state.message = t('wrong_pin');
    render();
}

async function openControl(fresh) {
    show('control', { sensitive: await loadSensitive(), fresh, search: '' });
}

async function usbExport() {
    const sensitive = state.sensitive ?? (await loadSensitive());
    const numbers = new Map(sensitive.people.map((person) => [person.id, person]));
    const names = new Map(state.roster.people.map((person) => [person.id, person.name]));
    const rejected = await all('rejected');
    const rows = [[t('name'), t('personal_number'), t('employer'), t('start'), t('end'), 'status']];

    for (const shift of sensitive.shifts) {
        rows.push([shift.name, shift.personal_number, shift.employer_name ? `${shift.employer_name} (${shift.employer_org_number})` : '', dateTime(shift.start), dateTime(shift.end), 'server']);
    }

    for (const event of [...sendOrder(state.queue), ...rejected]) {
        const person = numbers.get(event.person_id) ?? {};
        rows.push([names.get(event.person_id) ?? event.person_id, person.personal_number ?? '', person.employer_name ?? '', event.direction === 'IN' ? dateTime(event.occurred_at) : '', event.direction === 'OUT' ? dateTime(event.occurred_at) : '', event.reason ? `rejected:${event.reason}` : 'queued']);
    }

    const readme = [
        `NONOX Personalliggare – ${state.roster.company.name} (${state.roster.company.org_number})`,
        `${state.roster.location.name}, ${state.roster.location.address}`,
        `Exported from kiosk "${state.roster.device.name}" at ${new Date().toISOString()}`,
        `Last contact with the server: ${state.lastSync ?? 'never'}`,
        'personalliggare.csv: shifts the server had confirmed, then events still waiting on this device.',
        'koade-handelser.json: the waiting events exactly as stored, with the device hash chain.',
    ].join('\r\n');

    const archive = zip([
        { name: 'LASMIG.txt', content: readme },
        { name: 'personalliggare.csv', content: csv(rows) },
        { name: 'koade-handelser.json', content: JSON.stringify({ queued: sendOrder(state.queue), rejected }, null, 2) },
    ]);
    const link = el('a', { href: URL.createObjectURL(new Blob([archive], { type: 'application/zip' })), download: `personalliggare-${new Date().toISOString().slice(0, 10)}.zip` });
    document.body.append(link);
    link.click();
    link.remove();
}

// ---------- QR ----------

let stream = null;

async function startScan() {
    show('scan');

    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
    } catch {
        state.message = t('camera_denied');

        return render();
    }

    const video = document.getElementById('kiosk-video');
    video.srcObject = stream;
    await video.play();

    const detect = await qrDetector();

    const loop = async () => {
        if (state.screen !== 'scan') {
            return stopScan();
        }

        const value = await detect(video).catch(() => null);

        if (value) {
            const hash = await sha256(value);
            const person = state.roster.people.find((candidate) => candidate.qr_hash && candidate.qr_hash === hash);

            if (person) {
                stopScan();

                return record(person, 'KIOSK_QR');
            }

            // Not render(): that would replace the video element and stop the camera.
            document.getElementById('kiosk-scan-message').textContent = t('qr_unknown');
        }

        requestAnimationFrame(loop);
    };

    loop();
}

// The built-in BarcodeDetector where it exists; jsQR (Apache-2.0) only where it does not, such as Safari on iPad.
async function qrDetector() {
    if ('BarcodeDetector' in window && (await window.BarcodeDetector.getSupportedFormats()).includes('qr_code')) {
        const detector = new window.BarcodeDetector({ formats: ['qr_code'] });

        return async (video) => (await detector.detect(video))[0]?.rawValue ?? null;
    }

    const { default: jsQR } = await import('jsqr');
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    return async (video) => {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        context.drawImage(video, 0, 0);
        const image = context.getImageData(0, 0, canvas.width, canvas.height);

        return jsQR(image.data, image.width, image.height)?.data ?? null;
    };
}

function stopScan() {
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

// ---------- pairing ----------

async function pair(code) {
    try {
        const response = await fetch(config.urls.pair, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ code }),
        });

        if (!response.ok) {
            throw new Error('pair');
        }

        const { token } = await response.json();
        await kv.set('token', token);
        await kv.delete('last_sequence');
        await kv.delete('last_hash');
        state.token = token;
        state.revoked = false;
        state.message = null;
        tabs?.postMessage('paired');
        history.replaceState(null, '', config.urls.shell);
        await refreshRoster();
        show('home');
    } catch {
        state.message = t('pair_failed');
        render();
    }
}

// ---------- screens ----------

function header() {
    const pending = state.queue.length;

    return el('header', { class: 'k-header' },
        el('div', {},
            el('p', { class: 'k-eyebrow' }, state.roster?.company?.name ?? 'NONOX'),
            el('h1', {}, state.roster?.location?.name ?? t('title'))),
        el('div', { class: 'k-status', role: 'status', 'aria-live': 'polite' },
            el('p', { class: 'k-clock' }, new Date().toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })),
            el('p', { class: state.online ? 'k-online' : 'k-offline' }, state.online ? (pending ? t('pending', { count: pending }) : t('synced')) : t('offline'))));
}

function languageBar() {
    return el('nav', { class: 'k-languages', 'aria-label': t('language') },
        config.languages.map(([code, label]) => el('button', {
            type: 'button', class: 'k-language', lang: code, 'aria-pressed': state.language === code ? 'true' : 'false',
            onclick: async () => { state.language = code; await kv.set('language', code); render(); },
        }, label)));
}

function initials(name) {
    return name.split(/\s+/).map((part) => part[0] ?? '').join('').slice(0, 2).toUpperCase();
}

function homeScreen() {
    const people = state.roster.people.filter((person) => person.pin_verifier);

    return el('main', { class: 'k-main' },
        el('h2', { class: 'k-lead' }, t('tap_name')),
        el('ul', { class: 'k-people' }, people.map((person) => {
            const since = presentSince(person, state.queue);

            return el('li', {}, el('button', {
                type: 'button', class: since ? 'k-person k-in' : 'k-person',
                onclick: () => show('pin', { person, pinLanguage: person.language }),
            },
            person.photo ? el('img', { src: person.photo, alt: '' }) : el('span', { class: 'k-initials', 'aria-hidden': 'true' }, initials(person.name)),
            el('span', { class: 'k-name' }, person.name),
            el('span', { class: 'k-since' }, since ? t('in_since', { time: time(since) }) : '')));
        })),
        el('div', { class: 'k-actions' },
            state.roster.location.qr_enabled ? el('button', { type: 'button', class: 'k-big', onclick: startScan }, t('scan_qr')) : null,
            el('button', { type: 'button', class: 'k-big k-quiet', onclick: () => show('manager-pin') }, t('control_visit'))));
}

function keypad() {
    const key = (label, onclick, extra = {}) => el('button', {
        type: 'button',
        onpointerdown: (event) => event.currentTarget.classList.add('k-pressed'),
        onclick,
        ...extra,
        class: `${extra.class ?? 'k-key'}${state.pressedKey === label ? ' k-pressed' : ''}`,
    }, label);

    return el('div', { class: 'k-keypad', dir: 'ltr' },
        ['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((digit) => key(digit, () => pressDigit(digit))),
        key(t('back'), () => show('home', { person: null }), { class: 'k-key k-key-text' }),
        key('0', () => pressDigit('0')),
        key('⌫', () => { flash('⌫'); state.pin = state.pin.slice(0, -1); render(); }, { 'aria-label': t('digit_delete') }));
}

function pinScreen(title) {
    return el('main', { class: 'k-main k-center k-pin' },
        el('h2', { class: 'k-lead' }, title),
        el('p', { class: 'k-sub' }, state.screen === 'manager-pin' ? t('manager_pin') : t('enter_pin')),
        el('div', { class: 'k-dots', 'aria-label': `${state.pin.length} / 4` }, [0, 1, 2, 3].map((i) => el('span', { class: i < state.pin.length ? 'k-dot k-filled' : 'k-dot' }))),
        // Always there, so a message never pushes the keypad down.
        el('p', { class: 'k-error', role: 'alert' }, state.message ?? ''),
        keypad());
}

function resultScreen() {
    const name = state.person.first_name;
    const isIn = state.direction === 'IN';

    return el('main', { class: `k-main k-center k-result ${isIn ? 'k-result-in' : 'k-result-out'}`, onclick: () => show('home', { person: null }) },
        el('p', { class: 'k-result-icon', 'aria-hidden': 'true' }, isIn ? '✓' : '→'),
        el('h2', { role: 'status' }, isIn ? t('welcome', { name }) : t('goodbye', { name })),
        el('p', { class: 'k-result-time' }, `${isIn ? t('checked_in') : t('checked_out')} ${time(state.at)}`));
}

function scanScreen() {
    return el('main', { class: 'k-main k-center' },
        el('h2', { class: 'k-lead' }, t('scan_qr')),
        el('video', { id: 'kiosk-video', class: 'k-video', playsinline: true, muted: true }),
        el('p', { id: 'kiosk-scan-message', class: 'k-error', role: 'alert' }, state.message ?? ''),
        el('button', { type: 'button', class: 'k-big k-quiet', onclick: () => { stopScan(); show('home'); } }, t('back')));
}

function controlScreen() {
    const sensitive = state.sensitive;
    const numbers = new Map(sensitive.people.map((person) => [person.id, person]));
    const needle = (state.search ?? '').toLowerCase();
    const match = (name, number) => !needle || name.toLowerCase().includes(needle) || (number ?? '').replace(/\D/g, '').includes(needle.replace(/\D/g, '') || '§');
    const present = state.roster.people.filter((person) => presentSince(person, state.queue));
    const rows = sensitive.shifts.filter((shift) => match(shift.name, shift.personal_number)).reverse();

    return el('main', { class: 'k-main k-control' },
        el('div', { class: 'k-control-head' },
            el('h2', {}, t('control_visit')),
            el('button', { type: 'button', class: 'k-big k-quiet', onclick: () => show('home', { sensitive: null }) }, t('close'))),
        state.fresh ? null : el('p', { class: 'k-note' }, t('cached_ledger', { days: 31 })),
        el('h3', {}, `${t('present_now')} (${present.length})`),
        present.length === 0 ? el('p', {}, t('no_one')) : el('table', { class: 'k-table' },
            el('thead', {}, el('tr', {}, el('th', {}, t('name')), el('th', {}, t('personal_number')), el('th', {}, t('employer')), el('th', {}, t('start')))),
            el('tbody', {}, present.map((person) => {
                const sensitivePerson = numbers.get(person.id) ?? {};

                return el('tr', {}, el('td', {}, person.name), el('td', {}, sensitivePerson.personal_number ?? ''), el('td', {}, sensitivePerson.employer_name ? `${sensitivePerson.employer_name} (${sensitivePerson.employer_org_number})` : ''), el('td', {}, dateTime(presentSince(person, state.queue))));
            }))),
        el('h3', {}, t('ledger')),
        el('label', { class: 'k-search' }, t('search'), el('input', {
            type: 'search', value: state.search ?? '', autocomplete: 'off',
            oninput: (event) => { state.search = event.target.value; render(); document.querySelector('.k-search input')?.focus(); },
        })),
        el('table', { class: 'k-table' },
            el('thead', {}, el('tr', {}, el('th', {}, t('name')), el('th', {}, t('personal_number')), el('th', {}, t('employer')), el('th', {}, t('start')), el('th', {}, t('end')))),
            el('tbody', {}, rows.map((shift) => el('tr', {},
                el('td', {}, shift.name), el('td', {}, shift.personal_number), el('td', {}, shift.employer_name ? `${shift.employer_name} (${shift.employer_org_number})` : ''),
                el('td', {}, dateTime(shift.start) || t('missing')), el('td', {}, dateTime(shift.end) || t('missing')))))),
        el('button', { type: 'button', class: 'k-big', onclick: usbExport }, t('export_usb')));
}

function isAppleTouch() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function connectingScreen() {
    return el('main', { class: 'k-main k-center' },
        el('h1', {}, t('title')),
        el('p', { class: 'k-sub' }, state.online ? t('connecting') : t('offline')));
}

function pairScreen(reason = null) {
    const code = new URLSearchParams(location.search).get('kod') ?? '';

    return el('main', { class: 'k-main k-center' },
        el('h1', {}, t('pair_title')),
        reason ? el('p', { class: 'k-error', role: 'alert' }, reason) : null,
        el('p', { class: 'k-sub' }, t('not_paired')),
        // A Home Screen web app on iOS has its own storage: pairing in a Safari tab does not carry over.
        isAppleTouch() && !navigator.standalone ? el('p', { class: 'k-sub' }, t('install_first')) : null,
        el('form', { class: 'k-pair', onsubmit: (event) => { event.preventDefault(); pair(event.target.code.value); } },
            el('label', {}, t('pair_code'), el('input', { name: 'code', value: code, autocomplete: 'off', autocapitalize: 'characters', required: true, inputmode: 'text' })),
            el('button', { type: 'submit', class: 'k-big' }, t('pair_button'))),
        state.message ? el('p', { class: 'k-error', role: 'alert' }, state.message) : null);
}

function render() {
    const language = state.screen === 'pin' || state.screen === 'result' ? state.person?.language ?? state.language : state.language;
    const previous = state.language;
    state.language = language;
    setLanguage(language);

    let screen;

    if (!state.token) {
        screen = pairScreen();
    } else if (!state.roster) {
        // Paired, but the staff has not been fetched yet: never ask for a new code then.
        screen = connectingScreen();
    } else if (state.revoked) {
        screen = pairScreen(t('revoked'));
    } else {
        screen = {
            home: homeScreen,
            pin: () => pinScreen(state.person.name),
            'manager-pin': () => pinScreen(t('control_visit')),
            result: resultScreen,
            scan: scanScreen,
            control: controlScreen,
        }[state.screen]();
    }

    app.replaceChildren(state.token && state.roster ? header() : '', state.screen === 'home' && state.roster ? languageBar() : '', screen);
    state.language = previous;
}

// ---------- start ----------

// Kiosk tabs share one IndexedDB: when one is paired, the others start over with its token.
const tabs = 'BroadcastChannel' in window ? new BroadcastChannel('nonox-personalliggare') : null;

if (tabs) {
    tabs.onmessage = (event) => {
        if (event.data === 'paired') {
            location.replace(config.urls.shell);
        }
    };
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(config.urls.serviceWorker, { scope: config.urls.scope }).catch(() => {});
}

window.addEventListener('online', () => { state.online = true; sync(); refreshRoster(); });
window.addEventListener('offline', () => { state.online = false; render(); });
// A tablet that slept may have missed several refreshes (new people, changed codes).
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { refreshRoster(); } });
document.addEventListener('keydown', (event) => {
    if (/^\d$/.test(event.key) && (state.screen === 'pin' || state.screen === 'manager-pin')) {
        pressDigit(event.key);
    } else if (event.key === 'Escape' && state.screen !== 'home') {
        stopScan();
        show('home', { person: null });
    }
});

async function start() {
    db = await openDb();
    state.token = (await kv.get('token')) ?? null;
    state.roster = (await kv.get('roster')) ?? null;
    state.lastSync = (await kv.get('last_sync')) ?? null;
    state.queue = await all('queue');
    state.language = (await kv.get('language')) ?? 'sv';

    setInterval(sync, SYNC_MS);
    setInterval(refreshRoster, ROSTER_MS);
    setInterval(() => state.screen === 'home' && render(), 30000);

    render();

    if (state.token) {
        refreshRoster().then(sync);
    }
}

start();
