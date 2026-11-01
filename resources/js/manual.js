/*
 * The user manual (/manual): instant search over every article and the
 * menu on narrow screens. The pages work without this file; it only adds to
 * them. Its own entry, so the app's bundle is not loaded. The index comes
 * from /manual/sokindex.json the first time a search box is used.
 */

/** One character's search form: lowercase, without diacritics, so "lon" finds "lön" and "Ö" finds "o". */
function foldChar(char) {
    return char.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

/** Lowercase, without diacritics. */
export function normalize(text) {
    return [...String(text)].map(foldChar).join('');
}

/** The words of a query, folded. */
export function words(query) {
    return normalize(query).split(/[\s,.;:!?"()]+/).filter(Boolean);
}

/** How much each field counts: the title most, the body text least. */
const WEIGHTS = { t: 10, k: 6, h: 4, d: 3, b: 1 };

function folded(entry) {
    entry._n ??= Object.fromEntries(Object.keys(WEIGHTS).map((field) => [field, normalize(entry[field] ?? '')]));

    return entry._n;
}

/**
 * The entries matching every word of the query, best first: a word in the
 * title counts most (more at the start of a word), then the search words,
 * the headings, the description and last the body text.
 */
export function search(index, query, limit = 10) {
    const terms = words(query);

    if (terms.length === 0) {
        return [];
    }

    const found = [];

    for (const entry of index) {
        const fields = folded(entry);
        let score = 0;
        let matched = true;

        for (const term of terms) {
            let best = 0;

            for (const [field, weight] of Object.entries(WEIGHTS)) {
                const at = fields[field].indexOf(term);

                if (at === -1) {
                    continue;
                }

                const wordStart = at === 0 || /[^a-z0-9]/.test(fields[field][at - 1]);
                best = Math.max(best, weight * (wordStart ? 1.5 : 1));
            }

            if (best === 0) {
                matched = false;
                break;
            }

            score += best;
        }

        if (matched) {
            // A shorter title that matches is a closer match.
            found.push({ entry, score: score - fields.t.length / 1000 });
        }
    }

    return found.sort((a, b) => b.score - a.score).slice(0, limit).map(({ entry }) => entry);
}

/**
 * The text split into parts, the ones matching a word marked, compared
 * folded so "lon" marks "lön" while the text keeps its own letters.
 */
export function highlight(text, terms) {
    const chars = [...String(text)];
    let folded = '';
    const origin = [];

    chars.forEach((char, i) => {
        const fold = foldChar(char);
        folded += fold;
        for (let k = 0; k < fold.length; k++) {
            origin.push(i);
        }
    });

    const marked = new Array(chars.length).fill(false);

    for (const term of terms) {
        let at = folded.indexOf(term);

        while (term !== '' && at !== -1) {
            for (let k = at; k < at + term.length; k++) {
                marked[origin[k]] = true;
            }
            at = folded.indexOf(term, at + term.length);
        }
    }

    const parts = [];

    chars.forEach((char, i) => {
        const last = parts[parts.length - 1];

        if (last && last.mark === marked[i]) {
            last.text += char;
        } else {
            parts.push({ text: char, mark: marked[i] });
        }
    });

    return parts;
}

/** A short piece of the body text around the first word that matches, for a hit found only there. */
export function snippet(text, terms, around = 70) {
    const value = String(text);
    const folded = normalize(value);
    const at = Math.min(...terms.map((term) => folded.indexOf(term)).filter((i) => i !== -1));

    if (!Number.isFinite(at)) {
        return value.slice(0, around * 2);
    }

    const start = Math.max(0, at - around);
    const end = Math.min(value.length, at + around);

    return `${start > 0 ? '…' : ''}${value.slice(start, end).trim()}${end < value.length ? '…' : ''}`;
}

function marked(text, terms) {
    const fragment = document.createDocumentFragment();

    for (const part of highlight(text, terms)) {
        if (part.mark) {
            const mark = document.createElement('mark');
            mark.textContent = part.text;
            fragment.append(mark);
        } else {
            fragment.append(document.createTextNode(part.text));
        }
    }

    return fragment;
}

let indexPromise = null;

function loadIndex(url) {
    indexPromise ??= fetch(url, { headers: { Accept: 'application/json' } })
        .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
        .catch((error) => {
            indexPromise = null;
            throw error;
        });

    return indexPromise;
}

let openMenu = () => {};

function setupSearch(box) {
    const input = box.querySelector('[data-mn-input]');
    const results = box.querySelector('[data-mn-results]');
    const status = box.querySelector('[data-mn-status]');
    let index = null;
    let failed = false;

    const links = () => [...results.querySelectorAll('a')];

    const render = () => {
        const query = input.value.trim();
        results.replaceChildren();

        if (query === '') {
            status.textContent = '';

            return;
        }

        if (index === null) {
            if (failed) {
                const error = document.createElement('p');
                error.className = 'mn-results-empty';
                error.textContent = results.dataset.error;
                results.append(error);
            }

            return;
        }

        const terms = words(query);
        const found = search(index, query);
        status.textContent = found.length === 0 ? results.dataset.empty : `${found.length} ${found.length === 1 ? 'träff' : 'träffar'}. Pil ned går till träffarna.`;

        if (found.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'mn-results-empty';
            empty.textContent = results.dataset.empty;
            results.append(empty);

            return;
        }

        const list = document.createElement('ul');
        list.className = 'mn-results';

        for (const entry of found) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            const title = document.createElement('span');
            const section = document.createElement('span');
            const text = document.createElement('span');
            const fields = folded(entry);
            const inSummary = terms.every((term) => fields.t.includes(term) || fields.d.includes(term) || fields.k.includes(term));

            link.href = entry.u;
            title.className = 'mn-result-title';
            title.append(marked(entry.t, terms));
            section.className = 'mn-result-section';
            section.textContent = entry.s;
            text.className = 'mn-result-text';
            text.append(marked(inSummary || !entry.b ? entry.d : snippet(entry.b, terms), terms));
            link.append(title, section, text);
            item.append(link);
            list.append(item);
        }

        results.append(list);
    };

    const ensureIndex = () => {
        if (index !== null) {
            return;
        }

        loadIndex(box.dataset.index)
            .then((data) => { index = data; failed = false; render(); })
            .catch(() => { failed = true; render(); });
    };

    input.addEventListener('focus', ensureIndex);
    input.addEventListener('input', () => { ensureIndex(); render(); });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && links()[0]) {
            event.preventDefault();
            links()[0].focus();
        }
        if (event.key === 'Enter' && links()[0]) {
            event.preventDefault();
            links()[0].click();
        }
        if (event.key === 'Escape') {
            input.value = '';
            render();
        }
    });
    results.addEventListener('keydown', (event) => {
        const items = links();
        const at = items.indexOf(document.activeElement);

        if (at === -1) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            items[Math.min(at + 1, items.length - 1)].focus();
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            (at === 0 ? input : items[at - 1]).focus();
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            input.focus();
        }
    });
}

function setupShortcut() {
    document.addEventListener('keydown', (event) => {
        const active = document.activeElement;
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(active?.tagName ?? '') || active?.isContentEditable;
        const slash = event.key === '/' && !typing && !event.metaKey && !event.ctrlKey && !event.altKey;
        const commandK = event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey);

        if (!slash && !commandK) {
            return;
        }

        // The big box on the start page, otherwise the one in the menu (opened on a narrow screen).
        const hero = document.querySelector('.mn-search-hero [data-mn-input]');
        const input = hero ?? document.querySelector('[data-mn-input]');

        if (!input) {
            return;
        }

        event.preventDefault();
        if (!hero) {
            openMenu(true);
        }
        input.focus();
        input.select();
    });
}

function setupMenu() {
    const button = document.querySelector('[data-mn-menu]');
    const side = document.getElementById('mn-side');

    if (!button || !side) {
        return;
    }

    openMenu = (open) => {
        if (window.matchMedia('(min-width: 1024px)').matches) {
            return;
        }

        side.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', String(open));
        document.body.style.overflow = open ? 'hidden' : '';
    };

    button.addEventListener('click', () => openMenu(!side.classList.contains('is-open')));
    side.addEventListener('click', (event) => { if (event.target.closest('a')) { openMenu(false); } });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && side.classList.contains('is-open') && !event.target.closest?.('[data-mn-search]')) {
            openMenu(false);
            button.focus();
        }
    });
}

if (typeof document !== 'undefined') {
    const start = () => {
        setupMenu();
        document.querySelectorAll('[data-mn-search]').forEach(setupSearch);
        setupShortcut();

        // The start page is where people search: fetch the index while the reader reads the heading.
        const hero = document.querySelector('.mn-search-hero');
        if (hero) {
            (window.requestIdleCallback ?? setTimeout)(() => loadIndex(hero.dataset.index).catch(() => {}));
        }
    };

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
}
