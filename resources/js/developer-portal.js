/*
 * The developer site (/utvecklare): search over the operations and guides,
 * code tabs that remember the chosen language, copy buttons and the menu on
 * narrow screens. Everything works without this file; it only adds to the
 * server-rendered pages. Its own entry, so the app's bundle is not loaded.
 */

const LANGUAGE_KEY = 'nonox.developers.language';

const storage = {
    get(key) {
        try { return window.localStorage.getItem(key); } catch { return null; }
    },
    set(key, value) {
        try { window.localStorage.setItem(key, value); } catch { /* private mode: the choice lasts for this page */ }
    },
};

/** Lowercase, without diacritics, so "forfallo" finds "förfallo". */
export function normalize(text) {
    return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

/** The index entries matching every word of the query, best first. */
export function search(index, query, limit = 12) {
    const words = normalize(query).split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return [];
    }

    return index
        .map((entry) => {
            const title = normalize(entry.t);
            const haystack = `${title} ${normalize(entry.s)} ${normalize(entry.k)}`;

            if (!words.every((word) => haystack.includes(word))) {
                return null;
            }

            const score = words.reduce((sum, word) => sum + (title.startsWith(word) ? 3 : title.includes(word) ? 2 : 1), 0);

            return { entry, score };
        })
        .filter(Boolean)
        .sort((a, b) => b.score - a.score)
        .slice(0, limit)
        .map(({ entry }) => entry);
}

function setupSearch() {
    const input = document.querySelector('[data-dv-search]');
    const results = document.querySelector('[data-dv-results]');
    const data = document.getElementById('dv-search-index');

    if (!input || !results || !data) {
        return;
    }

    const index = JSON.parse(data.textContent || '[]');
    let active = -1;

    const links = () => [...results.querySelectorAll('a')];
    const select = (position) => {
        const items = links();
        active = items.length === 0 ? -1 : (position + items.length) % items.length;
        items.forEach((link, i) => link.setAttribute('aria-selected', String(i === active)));
        items[active]?.scrollIntoView({ block: 'nearest' });
    };

    const render = () => {
        const query = input.value.trim();
        results.replaceChildren();
        active = -1;

        if (query === '') {
            return;
        }

        const found = search(index, query);

        if (found.length === 0) {
            const empty = document.createElement('p');
            empty.textContent = results.dataset.empty;
            results.append(empty);

            return;
        }

        const list = document.createElement('ul');
        list.className = 'dv-results';

        for (const entry of found) {
            const item = document.createElement('li');
            const link = document.createElement('a');
            const sub = document.createElement('span');
            link.href = entry.u;
            link.textContent = entry.t;
            sub.className = 'dv-result-sub';
            sub.textContent = entry.s;
            link.append(sub);
            item.append(link);
            list.append(item);
        }

        results.append(list);
    };

    input.addEventListener('input', render);
    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') { event.preventDefault(); select(active + 1); }
        if (event.key === 'ArrowUp') { event.preventDefault(); select(active - 1); }
        if (event.key === 'Enter' && links()[Math.max(active, 0)]) { event.preventDefault(); links()[Math.max(active, 0)].click(); }
        if (event.key === 'Escape') { input.value = ''; render(); }
    });

    document.addEventListener('keydown', (event) => {
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName ?? '') || document.activeElement?.isContentEditable;

        if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
            event.preventDefault();
            openMenu(true);
            input.focus();
        }
    });
}

let openMenu = () => {};

function setupMenu() {
    const button = document.querySelector('[data-dv-menu]');
    const side = document.getElementById('dv-side');

    if (!button || !side) {
        return;
    }

    openMenu = (open) => {
        if (window.matchMedia('(min-width: 960px)').matches) {
            return;
        }

        side.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', String(open));
        document.body.style.overflow = open ? 'hidden' : '';
    };

    button.addEventListener('click', () => openMenu(!side.classList.contains('is-open')));
    side.addEventListener('click', (event) => { if (event.target.closest('a')) { openMenu(false); } });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && side.classList.contains('is-open')) { openMenu(false); button.focus(); }
    });
}

function chooseTab(name) {
    document.querySelectorAll('[data-tabs]').forEach((group) => {
        const tabs = [...group.querySelectorAll('[role=tab]')];
        const chosen = tabs.find((tab) => tab.dataset.tab === name);

        if (!chosen) {
            return;
        }

        tabs.forEach((tab) => {
            const selected = tab === chosen;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            document.getElementById(tab.getAttribute('aria-controls')).hidden = !selected;
        });
    });
}

function setupTabs() {
    const groups = [...document.querySelectorAll('[data-tabs]')];

    groups.forEach((group) => {
        const list = group.querySelector('[role=tablist]');
        const tabs = [...group.querySelectorAll('[role=tab]')];
        list.hidden = false;
        group.classList.add('is-ready');
        tabs.forEach((tab, i) => document.getElementById(tab.getAttribute('aria-controls')).hidden = i !== 0);

        tabs.forEach((tab, i) => {
            tab.addEventListener('click', () => {
                chooseTab(tab.dataset.tab);
                storage.set(LANGUAGE_KEY, tab.dataset.tab);
            });
            tab.addEventListener('keydown', (event) => {
                const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
                const edge = { Home: 0, End: tabs.length - 1 }[event.key];

                if (step === undefined && edge === undefined) {
                    return;
                }

                event.preventDefault();
                const next = tabs[edge ?? (i + step + tabs.length) % tabs.length];
                next.focus();
                next.click();
            });
        });
    });

    const remembered = storage.get(LANGUAGE_KEY);

    if (remembered) {
        chooseTab(remembered);
    }
}

function setupCopy() {
    document.querySelectorAll('[data-copy]').forEach((button) => {
        if (!navigator.clipboard) {
            return;
        }

        const label = button.textContent;
        button.hidden = false;
        button.addEventListener('click', async () => {
            const code = button.closest('.dv-code').querySelector('code').textContent;

            try {
                await navigator.clipboard.writeText(code);
                button.textContent = button.dataset.copied;
            } catch {
                return;
            }

            setTimeout(() => { button.textContent = label; }, 2000);
        });
    });
}

function setupCurrentOperation() {
    const links = [...document.querySelectorAll('.dv-ops a')];
    const mark = () => {
        links.forEach((link) => {
            const url = new URL(link.href);
            const current = url.pathname === window.location.pathname && url.hash === window.location.hash && url.hash !== '';
            current ? link.setAttribute('aria-current', 'location') : link.removeAttribute('aria-current');
        });
    };

    mark();
    window.addEventListener('hashchange', mark);
}

if (typeof document !== 'undefined') {
    const start = () => {
        setupMenu();
        setupSearch();
        setupTabs();
        setupCopy();
        setupCurrentOperation();
    };

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
}
