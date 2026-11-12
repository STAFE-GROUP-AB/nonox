// NONOX Verkstadsterminal (ADR-071 §7). A shared tablet in the workshop: a
// screen of pictures, a personal code, the open production orders, and
// hours and minutes for today. Online only: the page is cached so it starts
// at once, but a report that could not be sent is never shown as saved. The
// tablet holds only its device token (localStorage); the worker's session
// token lives in memory and the tablet signs out on its own after a minute
// of nothing.
import { addMinutes, fill, formatDuration, initials, minutesSince, totalMinutes } from './workshop-core.js';
import '../css/workshop.css';

const config = JSON.parse(document.getElementById('workshop-config').textContent);
const app = document.getElementById('workshop');
const TOKEN_KEY = 'nonox-workshop-device';
const IDLE_MS = 60000;
const NOTICE_MS = 3500;

const state = {
    token: null,
    screen: 'start',
    people: [],
    person: null,
    code: '',
    session: null,
    orders: [],
    mine: [],
    order: null,
    operation: null,
    time: { hours: 0, minutes: 0 },
    waiting: { kind: 'MATERIAL', reason: '' },
    notice: null,
    busy: false,
    idleTimer: null,
    noticeTimer: null,
};

const t = (key, replace = {}) => fill(config.strings[key] ?? key, replace);
const duration = (minutes) => formatDuration(minutes, t);

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

function storedToken() {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

function keepToken(token) {
    try {
        if (token === null) {
            localStorage.removeItem(TOKEN_KEY);
        } else {
            localStorage.setItem(TOKEN_KEY, token);
        }
    } catch {
        // Private mode: the tablet must be paired again after a restart.
    }
}

// ---------- server ----------

async function call(url, { method = 'GET', body, device = true, session = false } = {}) {
    const headers = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (device && state.token) {
        headers.Authorization = `Bearer ${state.token}`;
    }

    if (session && state.session) {
        headers['X-Workshop-Session'] = state.session;
    }

    let response;

    try {
        response = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), cache: 'no-store' });
    } catch {
        return { status: 0, data: null };
    }

    let data = null;

    try {
        data = await response.json();
    } catch {
        // Not JSON: treated as a failure by status.
    }

    return { status: response.status, data };
}

/** What the screens do with a failed call; true when it was handled. */
function failed(result) {
    if (result.status === 0) {
        notice(t('no_connection'), 'error');
    } else if (result.status === 401 && result.data?.error === 'device_unknown') {
        keepToken(null);
        state.token = null;
        show('start');
        notice(t('device_unknown'), 'error');
    } else if (result.status === 401) {
        signOut();
        notice(t('session_ended'), 'error');
    } else if (result.status === 404) {
        notice(t('terminal_off'), 'error');
    } else if (result.status === 422 && result.data?.message) {
        notice(result.data.message, 'error');
    } else {
        notice(t('something_wrong'), 'error');
    }

    return true;
}

function take(data) {
    state.session = data.session ?? state.session;
    state.orders = data.orders ?? state.orders;
    state.mine = data.mine ?? state.mine;
}

// ---------- screens ----------

function notice(text, tone = 'ok') {
    state.notice = { text, tone };
    clearTimeout(state.noticeTimer);
    state.noticeTimer = setTimeout(() => {
        state.notice = null;
        render();
    }, NOTICE_MS);
    render();
}

function show(screen) {
    state.screen = screen;
    render();
}

function signOut() {
    state.session = null;
    state.person = null;
    state.code = '';
    state.order = null;
    state.operation = null;
    state.orders = [];
    state.mine = [];
    clearTimeout(state.idleTimer);
    state.screen = state.token ? 'people' : 'start';

    if (state.token) {
        loadPeople();
    }

    render();
}

function touch() {
    clearTimeout(state.idleTimer);

    if (state.session) {
        state.idleTimer = setTimeout(signOut, IDLE_MS);
    }
}

async function loadPeople() {
    const result = await call(config.urls.people);

    if (result.status !== 200) {
        failed(result);

        return;
    }

    state.people = result.data.people;
    render();
}

async function loadOrders() {
    const result = await call(config.urls.orders, { session: true });

    if (result.status !== 200) {
        failed(result);

        return;
    }

    take(result.data);
    render();
}

async function pair(code) {
    const result = await call(config.urls.pair, { method: 'POST', body: { code }, device: false });

    if (result.status === 422) {
        notice(t('pair_invalid'), 'error');

        return;
    }

    if (result.status !== 200) {
        failed(result);

        return;
    }

    state.token = result.data.token;
    keepToken(state.token);
    state.screen = 'people';
    await loadPeople();
    render();
}

async function enterCode(digit) {
    if (state.busy || state.code.length >= 4) {
        return;
    }

    state.code += digit;
    render();

    if (state.code.length < 4) {
        return;
    }

    state.busy = true;
    const result = await call(config.urls.signIn, { method: 'POST', body: { person_id: state.person.id, code: state.code } });
    state.busy = false;
    state.code = '';

    if (result.status === 429) {
        show('people');
        notice(t(result.data?.paused ? 'device_paused' : (result.data?.blocked ? 'code_blocked' : 'code_locked')), 'error');

        return;
    }

    if (result.status !== 200) {
        failed(result);

        return;
    }

    if (!result.data.ok) {
        notice(t('code_wrong'), 'error');

        return;
    }

    state.session = result.data.token;
    show('orders');
    touch();
    await loadOrders();
}

async function send(url, body, successText) {
    if (state.busy) {
        return false;
    }

    state.busy = true;
    render();
    const result = await call(url, { method: 'POST', body, session: true });
    state.busy = false;

    if (result.status !== 200) {
        failed(result);
        render();

        return false;
    }

    take(result.data);
    state.order = state.order ? state.orders.find((order) => order.id === state.order.id) ?? null : null;
    state.operation = state.order && state.operation ? state.order.operations.find((operation) => operation.id === state.operation.id) ?? null : null;
    notice(successText);

    return true;
}

// ---------- pieces ----------

const note = () => el('p', { class: 'w-note' }, t('production_time_note'));

function header(title, { back = null } = {}) {
    return el('header', { class: 'w-header' },
        back ? el('button', { class: 'w-back', type: 'button', onclick: back }, t('back')) : el('span'),
        el('h1', {}, title),
        state.session ? el('button', { class: 'w-out', type: 'button', onclick: signOut }, t('sign_out')) : el('span'),
    );
}

function banner() {
    return state.notice ? el('p', { class: `w-notice w-notice-${state.notice.tone}`, role: 'status' }, state.notice.text) : null;
}

function tile(person) {
    return el('button', { class: 'w-person', type: 'button', onclick: () => { state.person = person; state.code = ''; show('code'); } },
        person.photo
            ? el('img', { src: person.photo, alt: '', class: 'w-photo' })
            : el('span', { class: 'w-photo w-initials', 'aria-hidden': 'true' }, initials(person.name)),
        el('span', { class: 'w-name' }, person.name),
    );
}

function startScreen() {
    const input = el('input', { id: 'pair-code', class: 'w-input', autocomplete: 'off', autocapitalize: 'characters', inputmode: 'text', maxlength: '9', 'aria-label': t('pair_label'), placeholder: 'ABCD-EFGH', value: new URLSearchParams(location.hash.slice(1)).get('kod') ?? '' });

    return el('main', { class: 'w-center' },
        el('h1', {}, t('pair_title')),
        el('p', { class: 'w-help' }, t('pair_help')),
        el('form', { class: 'w-form', onsubmit: (event) => { event.preventDefault(); pair(input.value); } },
            el('label', { for: 'pair-code' }, t('pair_label')), input,
            el('button', { class: 'w-primary', type: 'submit' }, t('pair_button'))),
    );
}

function peopleScreen() {
    return [
        header(t('people_title')),
        el('main', { class: 'w-main' },
            el('p', { class: 'w-help' }, t('people_help')),
            state.people.length === 0
                ? el('p', { class: 'w-empty' }, t('people_empty'))
                : el('div', { class: 'w-people' }, state.people.map(tile)),
            note(),
            el('p', { class: 'w-note' }, t('what_is_saved'))),
    ];
}

function codeScreen() {
    const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'back', '0', 'delete'];

    return [
        header(fill(t('code_title'), { name: state.person.name.split(' ')[0] }), { back: () => show('people') }),
        el('main', { class: 'w-center' },
            el('p', { class: 'w-help' }, t('code_help')),
            el('div', { class: 'w-dots', 'aria-hidden': 'true' }, [0, 1, 2, 3].map((index) => el('span', { class: index < state.code.length ? 'on' : '' }))),
            el('div', { class: 'w-keypad' }, keys.map((key) => {
                if (key === 'back') {
                    return el('span');
                }

                if (key === 'delete') {
                    return el('button', { type: 'button', class: 'w-key w-key-small', onclick: () => { state.code = state.code.slice(0, -1); render(); } }, t('delete'));
                }

                return el('button', { type: 'button', class: 'w-key', onclick: () => enterCode(key) }, key);
            }))),
    ];
}

function waitingBadge(operation) {
    if (!operation.waiting) {
        return null;
    }

    const since = duration(minutesSince(operation.waiting.since));

    return el('p', { class: `w-wait w-wait-${operation.waiting.kind.toLowerCase()}` },
        `${operation.waiting.label}: ${operation.waiting.reason} (${fill(t('waiting_since'), { time: since })})`);
}

function ordersScreen() {
    return [
        header(t('orders_title')),
        el('main', { class: 'w-main' },
            el('div', { class: 'w-actions' },
                el('button', { class: 'w-secondary', type: 'button', onclick: () => show('mine') }, t('my_times'))),
            note(),
            state.orders.length === 0
                ? el('p', { class: 'w-empty' }, t('orders_empty'))
                : el('div', { class: 'w-orders' }, state.orders.map((order) => el('button', { class: 'w-order', type: 'button', onclick: () => { state.order = order; show('order'); } },
                    el('span', { class: 'w-ref' }, order.reference),
                    el('span', { class: 'w-product' }, order.product),
                    el('span', { class: 'w-meta' }, fill(t('quantity'), { quantity: order.quantity })),
                    order.customer ? el('span', { class: 'w-meta' }, fill(t('customer'), { customer: order.customer })) : null,
                    order.due_on ? el('span', { class: 'w-meta' }, fill(t('due'), { date: order.due_on })) : null,
                    order.operations.some((operation) => operation.waiting) ? el('span', { class: 'w-flag' }, t('waiting_button')) : null,
                )))),
    ];
}

function orderScreen() {
    const order = state.order;

    return [
        header(`${order.reference} ${order.product}`, { back: () => { state.order = null; show('orders'); } }),
        el('main', { class: 'w-main' },
            note(),
            el('h2', {}, t('operations')),
            el('ul', { class: 'w-operations' }, order.operations.map((operation) => el('li', { class: 'w-operation' },
                el('div', { class: 'w-op-head' },
                    el('strong', {}, operation.name),
                    el('span', { class: 'w-meta' }, [
                        fill(t('planned'), { time: duration(operation.planned_minutes) }),
                        fill(t('reported'), { time: duration(operation.reported_minutes) }),
                        operation.my_minutes_today ? fill(t('mine_today'), { time: duration(operation.my_minutes_today) }) : null,
                    ].filter(Boolean).join(' · '))),
                waitingBadge(operation),
                el('div', { class: 'w-op-actions' },
                    el('button', { class: 'w-primary', type: 'button', onclick: () => { state.operation = operation; state.time = { hours: 0, minutes: 0 }; show('report'); } }, t('report_title')),
                    el('button', { class: 'w-secondary', type: 'button', onclick: () => { state.operation = operation; state.waiting = { kind: 'MATERIAL', reason: '' }; show('waiting'); } }, t('waiting_button')),
                    operation.waiting ? el('button', { class: 'w-secondary', type: 'button', disabled: state.busy, onclick: () => send(config.urls.clearWait, { operation_id: operation.id }, t('waiting_cleared_ok')) }, t('waiting_clear')) : null),
            )))),
    ];
}

function reportScreen() {
    const add = (label, delta) => el('button', { type: 'button', class: 'w-step', onclick: () => { state.time = addMinutes(state.time, delta); render(); } }, label);
    const total = totalMinutes(state.time);

    return [
        header(t('report_title'), { back: () => show('order') }),
        el('main', { class: 'w-center' },
            note(),
            el('p', { class: 'w-help' }, fill(t('report_help'), { operation: state.operation.name })),
            el('p', { class: 'w-time', 'aria-live': 'polite' }, fill(t('total'), { time: duration(total) })),
            el('div', { class: 'w-steps' }, add('+ 1 h', 60), add('+ 30', 30), add('+ 15', 15), add('+ 5', 5), add('+ 1', 1)),
            el('div', { class: 'w-steps' }, add('− 1 h', -60), add('− 30', -30), add('− 15', -15), add('− 5', -5), add('− 1', -1)),
            el('div', { class: 'w-actions' },
                el('button', { class: 'w-secondary', type: 'button', onclick: () => { state.time = { hours: 0, minutes: 0 }; render(); } }, t('reset')),
                el('button', { class: 'w-primary', type: 'button', disabled: total < 1 || state.busy, onclick: async () => { if (await send(config.urls.report, { operation_id: state.operation.id, hours: state.time.hours, minutes: state.time.minutes }, t('reported_ok'))) { show('order'); } } }, t('report_button')))),
    ];
}

function waitingScreen() {
    const reason = el('input', { class: 'w-input', maxlength: '200', 'aria-label': t('waiting_reason'), value: state.waiting.reason, oninput: (event) => { state.waiting.reason = event.target.value; } });
    const kind = (value, label) => el('button', { type: 'button', class: `w-choice${state.waiting.kind === value ? ' on' : ''}`, 'aria-pressed': state.waiting.kind === value ? 'true' : 'false', onclick: () => { state.waiting.kind = value; render(); } }, label);

    return [
        header(t('waiting_title'), { back: () => show('order') }),
        el('main', { class: 'w-center' },
            el('div', { class: 'w-choices' }, kind('MATERIAL', t('waiting_material')), kind('WORK', t('waiting_work'))),
            el('label', { class: 'w-label' }, t('waiting_reason'), reason),
            el('p', { class: 'w-help' }, t('waiting_reason_help')),
            el('button', { class: 'w-primary', type: 'button', disabled: state.busy, onclick: async () => { if (await send(config.urls.wait, { operation_id: state.operation.id, kind: state.waiting.kind, reason: state.waiting.reason }, t('waiting_ok'))) { show('order'); } } }, t('waiting_save'))),
    ];
}

function mineScreen() {
    return [
        header(t('my_times'), { back: () => show('orders') }),
        el('main', { class: 'w-main' },
            note(),
            el('p', { class: 'w-help' }, t('my_times_help')),
            state.mine.length === 0
                ? el('p', { class: 'w-empty' }, t('my_times_empty'))
                : el('ul', { class: 'w-entries' }, state.mine.map((entry) => el('li', { class: 'w-entry' },
                    el('div', {},
                        el('strong', {}, `${entry.reference} ${entry.product}`),
                        el('span', { class: 'w-meta' }, `${entry.operation} · ${entry.worked_on}`),
                        entry.is_correction ? el('span', { class: 'w-flag' }, t('correction')) : null,
                        entry.corrected ? el('span', { class: 'w-flag' }, t('corrected')) : null),
                    el('span', { class: 'w-minutes' }, `${entry.minutes < 0 ? '−' : ''}${duration(Math.abs(entry.minutes))}`),
                    entry.can_correct ? el('button', { class: 'w-secondary', type: 'button', disabled: state.busy, onclick: () => { if (window.confirm(t('correct_confirm'))) { send(config.urls.correct, { entry_id: entry.id }, t('corrected_ok')); } } }, t('correct')) : null)))),
    ];
}

function render() {
    const screens = { start: startScreen, people: peopleScreen, code: codeScreen, orders: ordersScreen, order: orderScreen, report: reportScreen, waiting: waitingScreen, mine: mineScreen };
    const content = [screens[state.screen]?.() ?? startScreen(), banner()].flat().filter(Boolean);

    app.replaceChildren(...content);
    touch();
}

// ---------- start ----------

document.addEventListener('pointerdown', touch);
document.addEventListener('keydown', touch);

if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register(config.urls.serviceWorker, { scope: config.urls.scope }).catch(() => {});
}

state.token = storedToken();
state.screen = state.token ? 'people' : 'start';
render();

if (state.token) {
    loadPeople();
}
