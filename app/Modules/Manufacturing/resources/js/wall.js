// NONOX Storskärm i verkstaden (ADR-071, M7). A television on the workshop
// wall: today's planned work, what is late, what is to be delivered, and
// what waits or stands still. Nobody signs in at the screen: it is paired
// once with a one-time code and keeps only its device token (localStorage).
// It asks for the board every 30 seconds and keeps showing the last answer,
// marked as old, while the connection is down. A signed-in user sees the
// same page as a preview, fed from the session.
import { clock, codeFromHash, fill, formatDay, isStale, lateText, withoutCode } from './wall-core.js';
import '../css/wall.css';

const config = JSON.parse(document.getElementById('wall-config').textContent);
const app = document.getElementById('wall');
const TOKEN_KEY = 'nonox-wall-screen';

const state = {
    token: null,
    board: null,
    lastOk: null,
    screen: 'loading',
    notice: null,
    timer: null,
};

const t = (key, replace = {}) => fill(config.strings[key] ?? key, replace);

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
        // Private mode: the screen must be paired again after a restart.
    }
}

// ---------- server ----------

async function call(url, { method = 'GET', body } = {}) {
    const headers = { Accept: 'application/json' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    if (!config.preview && state.token) {
        headers.Authorization = `Bearer ${state.token}`;
    }

    let response;

    try {
        response = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), cache: 'no-store', credentials: 'same-origin' });
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

async function load() {
    const result = await call(config.urls.board);

    if (result.status === 200 && result.data) {
        state.board = result.data;
        state.lastOk = Date.now();
        state.screen = 'board';
    } else if (result.status === 401 && !config.preview) {
        keepToken(null);
        state.token = null;
        state.board = null;
        state.screen = 'pair';
    } else if (result.status === 404) {
        state.board = null;
        state.screen = 'off';
    } else if (state.board === null) {
        state.screen = 'loading';
        state.notice = t('no_connection');
    }

    render();
    schedule();
}

function schedule() {
    clearTimeout(state.timer);

    if (state.screen === 'pair') {
        return;
    }

    state.timer = setTimeout(load, (state.board?.refresh_seconds ?? 30) * 1000);
}

async function pair(code) {
    const result = await call(config.urls.pair, { method: 'POST', body: { code } });

    if (result.status !== 200 || !result.data?.token) {
        state.notice = result.status === 0 ? t('no_connection') : t('pair_invalid');
        state.screen = 'pair';
        render();

        return;
    }

    state.token = result.data.token;
    keepToken(state.token);
    state.notice = null;
    state.screen = 'loading';
    render();
    await load();
}

// ---------- screens ----------

function pairScreen() {
    const input = el('input', { id: 'pair-code', class: 'wall-input', autocomplete: 'off', autocapitalize: 'characters', maxlength: '9', placeholder: 'ABCD-EFGH', value: state.pendingCode ?? '' });

    return el('main', { class: 'wall-center' },
        el('h1', {}, t('pair_title')),
        el('p', { class: 'wall-help' }, t('pair_help')),
        el('form', { class: 'wall-form', onsubmit: (event) => { event.preventDefault(); pair(input.value); } },
            el('label', { for: 'pair-code' }, t('pair_label')), input,
            el('button', { class: 'wall-primary', type: 'submit' }, t('pair_button'))),
        state.notice ? el('p', { class: 'wall-notice', role: 'alert' }, state.notice) : null,
    );
}

function message(text) {
    return el('main', { class: 'wall-center' }, el('h1', {}, t('title')), el('p', { class: 'wall-help' }, text));
}

function light(status, label) {
    return status ? el('span', { class: `wall-light wall-${status.toLowerCase()}` }, label) : null;
}

function more(count) {
    return count > 0 ? el('p', { class: 'wall-more' }, t('more', { count })) : null;
}

function panel(title, count, body, extra = {}) {
    return el('section', { class: `wall-panel ${extra.class ?? ''}`, 'aria-label': title },
        el('h2', {}, title, count > 0 ? el('span', { class: 'wall-count' }, String(count)) : null),
        body);
}

function orderTitle(item) {
    return el('div', { class: 'wall-order' },
        el('span', { class: 'wall-ref' }, item.reference),
        el('span', { class: 'wall-product' }, item.product),
        item.quantity ? el('span', { class: 'wall-qty' }, item.quantity) : null);
}

function todayPanel(board) {
    const body = board.work.length === 0
        ? el('p', { class: 'wall-empty' }, t('today_empty'))
        : el('ul', { class: 'wall-cards' }, board.work.map((order) => el('li', { class: 'wall-card' },
            el('div', { class: 'wall-card-head' }, orderTitle(order), light(order.status, order.status_label)),
            el('ul', { class: 'wall-steps' }, order.operations.map((step) => el('li', { class: `wall-step${step.now ? ' is-now' : ''}${step.done ? ' is-done' : ''}` },
                el('span', { class: 'wall-time' }, `${step.from}–${step.to}`),
                el('span', { class: 'wall-step-name' }, step.name),
                step.workstation ? el('span', { class: 'wall-station' }, step.workstation) : null,
                step.now ? el('span', { class: 'wall-tag' }, t('now')) : null,
                step.done ? el('span', { class: 'wall-tag wall-tag-quiet' }, t('done')) : null))))));

    return panel(t('today'), board.work.length + board.work_more, [body, more(board.work_more)], { class: 'wall-today' });
}

function latePanel(board) {
    const body = board.late.length === 0
        ? el('p', { class: 'wall-empty wall-good' }, t('late_empty'))
        : el('ul', { class: 'wall-rows' }, board.late.map((item) => el('li', { class: 'wall-row' },
            orderTitle(item),
            el('div', { class: 'wall-row-meta' },
                el('strong', { class: 'wall-red-text' }, lateText(item.days_late, t)),
                item.promised_on ? el('span', {}, t('promised', { date: formatDay(item.promised_on, board.today, t) })) : null,
                el('span', {}, t('forecast', { date: item.forecast_on ? formatDay(item.forecast_on, board.today, t) : t('after_horizon') })),
                item.waits_for ? el('span', {}, t('waits_for', { what: item.waits_for.toLowerCase() })) : null))));

    return panel(t('late'), board.late.length + board.late_more, [body, more(board.late_more)], { class: board.late.length > 0 ? 'wall-alert' : '' });
}

function deliveriesPanel(board) {
    const body = board.deliveries.length === 0
        ? el('p', { class: 'wall-empty' }, t('deliveries_empty'))
        : el('ul', { class: 'wall-rows' }, board.deliveries.map((item) => el('li', { class: 'wall-row' },
            el('div', { class: 'wall-date' }, formatDay(item.promised_on, board.today, t)),
            orderTitle(item),
            el('div', { class: 'wall-row-meta' }, light(item.status, item.status_label),
                el('span', {}, t('forecast', { date: item.forecast_on ? formatDay(item.forecast_on, board.today, t) : t('after_horizon') }))))));

    return panel(t('deliveries'), board.deliveries.length + board.deliveries_more, [body, more(board.deliveries_more)]);
}

function waitingPanel(board) {
    const rows = [
        ...board.stopped.map((stop) => el('li', { class: 'wall-row' },
            el('div', { class: 'wall-order' }, el('strong', { class: 'wall-red-text' }, t('stopped', { station: stop.workstation }))),
            el('div', { class: 'wall-row-meta' }, el('span', {}, stop.reason), el('span', {}, stop.until ? t('until', { time: stop.until }) : t('until_further'))))),
        ...board.waiting.map((item) => el('li', { class: 'wall-row' },
            orderTitle(item),
            el('div', { class: 'wall-row-meta' }, el('strong', { class: 'wall-amber-text' }, item.kind_label), el('span', {}, item.operation), el('span', {}, t('since', { time: item.since }))))),
    ];
    const count = board.stopped.length + board.waiting.length + board.waiting_more;

    return panel(t('waiting'), count, [rows.length === 0 ? el('p', { class: 'wall-empty wall-good' }, t('waiting_empty')) : el('ul', { class: 'wall-rows' }, rows), more(board.waiting_more)]);
}

function header(board) {
    const now = new Date();
    const date = new Intl.DateTimeFormat('sv-SE', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'Europe/Stockholm' }).format(now);
    const stale = isStale(state.lastOk, Date.now(), board.refresh_seconds);

    return el('header', { class: 'wall-header' },
        el('div', {}, el('p', { class: 'wall-company' }, board.company), el('h1', {}, t('title'))),
        el('div', { class: 'wall-status' },
            stale ? el('p', { class: 'wall-stale', role: 'status' }, t('stale', { time: clock(new Date(state.lastOk).toISOString()) })) : null,
            config.preview ? el('p', { class: 'wall-preview' }, t('preview')) : null,
            board.run ? el('p', {}, t('updated', { time: clock(board.run.at) })) : null),
        el('div', { class: 'wall-clock' }, el('p', { class: 'wall-time-now' }, clock(now.toISOString())), el('p', {}, date)));
}

function boardScreen(board) {
    if (board.run === null) {
        return [header(board), el('main', { class: 'wall-grid' }, el('p', { class: 'wall-empty wall-no-plan' }, t('no_plan')), waitingPanel(board))];
    }

    return [header(board), el('main', { class: 'wall-grid', 'data-wall-board': '' },
        todayPanel(board),
        el('div', { class: 'wall-side' }, latePanel(board), deliveriesPanel(board), waitingPanel(board)))];
}

function render() {
    let content;

    switch (state.screen) {
        case 'pair':
            content = pairScreen();
            break;
        case 'off':
            content = message(t('off'));
            break;
        case 'board':
            content = boardScreen(state.board);
            break;
        default:
            content = message(state.notice ?? t('loading'));
    }

    app.replaceChildren(...[content].flat());
}

// ---------- start ----------

// The pairing code is read from the fragment and removed from the address at once, so it is not left in the history.
state.pendingCode = codeFromHash(location.hash);

if (state.pendingCode !== '') {
    history.replaceState(null, '', withoutCode(location.href));
}

// The clock in the header moves on its own between answers.
setInterval(() => {
    if (state.screen === 'board') {
        render();
    }
}, 20000);

if (config.preview) {
    load();
} else {
    state.token = storedToken();

    if (state.token) {
        load();
    } else if (state.pendingCode !== '') {
        pair(state.pendingCode);
    } else {
        state.screen = 'pair';
        render();
    }
}
