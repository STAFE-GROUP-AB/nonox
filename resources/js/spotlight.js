/**
 * The spotlight search (ADR-026): ⌘K / Ctrl+K or "/" anywhere, the search
 * field in the top bar, or the icon on small screens. Results stream in per
 * group from POST /sok as newline-delimited JSON; the query never goes in
 * the URL.
 */

import { flatten, isEditable, readLines, segments, sortGroups, step, stepGroup, terms, withoutToken } from './spotlight-core.js';

const ICONS = {
    arrow: 'M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    voucher: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z',
    invoice: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    'supplier-invoice': 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z',
    quote: 'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z',
    order: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
    customer: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    supplier: 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 0 1-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 0 0-3.213-9.193 2.056 2.056 0 0 0-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 0 0-10.026 0 1.106 1.106 0 0 0-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12',
    person: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    article: 'M21 7.5l-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    account: 'M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z',
    asset: 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z',
    accrual: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
    project: 'M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z',
    'cost-center': 'M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z M6 6h.008v.008H6V6Z',
    payslip: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
    inbox: 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859m-19.5.338V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18v-4.162c0-.224-.034-.447-.1-.661L19.24 5.338a2.25 2.25 0 0 0-2.15-1.588H6.911a2.25 2.25 0 0 0-2.15 1.588L2.35 13.177a2.25 2.25 0 0 0-.1.661Z',
    building: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    upload: 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5',
    document: 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
};

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('spotlight', (config) => ({
        open: false,
        query: '',
        groups: [],
        chips: [],
        understood: '',
        loading: false,
        failed: false,
        activeUid: null,
        navigated: false,
        announcement: '',
        ms: null,
        returnFocus: null,
        controller: null,
        timer: null,
        mac: /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent),

        init() {
            window.addEventListener('keydown', (event) => this.globalKey(event));
            window.addEventListener('spotlight-open', () => this.show());
        },

        globalKey(event) {
            if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.open ? this.close() : this.show();

                return;
            }

            if (event.key === '/' && !this.open && !event.metaKey && !event.ctrlKey && !event.altKey && !isEditable(event.target)) {
                event.preventDefault();
                this.show();
            }
        },

        show() {
            if (this.open) {
                this.$refs.input.focus();

                return;
            }

            this.returnFocus = document.activeElement;
            this.open = true;
            document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => {
                this.$refs.input.focus();
                this.$refs.input.select();
            });
            this.search();
        },

        close() {
            this.open = false;
            this.controller?.abort();
            clearTimeout(this.timer);
            document.documentElement.classList.remove('overflow-hidden');
            this.$nextTick(() => this.returnFocus?.focus?.());
        },

        typed() {
            clearTimeout(this.timer);
            this.navigated = false;
            this.timer = setTimeout(() => this.search(), config.debounce ?? 120);
        },

        async search() {
            this.controller?.abort();
            const controller = new AbortController();
            this.controller = controller;
            this.loading = true;
            this.failed = false;
            let fresh = true;
            const received = [];

            try {
                const response = await fetch(config.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/x-ndjson',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ q: this.query }),
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                await readLines(response, (line) => {
                    if (controller.signal.aborted) {
                        return;
                    }

                    if (line.type === 'meta') {
                        this.chips = line.chips ?? [];
                        this.understood = line.text ?? this.query;
                    }

                    if (line.type === 'group') {
                        // The old results stay until the first new group arrives, so the list does not blink.
                        if (fresh) {
                            fresh = false;
                            received.length = 0;
                        }

                        received.push(line);
                        this.present(received);
                    }

                    if (line.type === 'done') {
                        if (fresh) {
                            this.present([]);
                        }

                        this.ms = line.ms;
                        this.announce(line.results, line.groups);
                    }
                });
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.failed = true;
                    this.announcement = config.texts.failed;
                }
            } finally {
                if (this.controller === controller) {
                    this.loading = false;
                }
            }
        },

        present(groups) {
            // "Visa alla" is an option of its own, so the keyboard reaches the list page too.
            this.groups = sortGroups(groups).map((group) => (group.more
                ? { ...group, results: [...group.results, { uid: `more:${group.key}`, type: 'more', id: group.key, title: config.texts.more.replace(':count', group.totalLabel), subtitle: group.label, url: group.more, icon: 'arrow', remember: false, more: true }] }
                : group));
            const flat = this.flat;

            if (!this.navigated || !flat.some((result) => result.uid === this.activeUid)) {
                this.activeUid = flat[0]?.uid ?? null;
            }
        },

        announce(results, groups) {
            if (this.query.trim() === '') {
                this.announcement = '';

                return;
            }

            this.announcement = results === 0
                ? config.texts.none
                : config.texts.count.replace(':results', results).replace(':groups', groups);
        },

        get flat() {
            return flatten(this.groups);
        },

        get active() {
            return this.flat.find((result) => result.uid === this.activeUid) ?? null;
        },

        get words() {
            // Only the free text is marked, not "lev" or "> 10000 kr".
            return terms(this.understood);
        },

        parts(text) {
            return segments(text, this.words);
        },

        icon(name) {
            return ICONS[name] ?? ICONS.document;
        },

        optionId(uid) {
            return `spotlight-option-${uid.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
        },

        move(delta) {
            this.navigated = true;
            this.activeUid = step(this.flat, this.activeUid, delta);
            this.reveal();
        },

        moveGroup(delta) {
            this.navigated = true;
            this.activeUid = stepGroup(this.groups, this.activeUid, delta);
            this.reveal();
        },

        reveal() {
            this.$nextTick(() => {
                if (this.activeUid) {
                    document.getElementById(this.optionId(this.activeUid))?.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        key(event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.move(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.move(-1);
            } else if (event.key === 'Home' && event.ctrlKey) {
                event.preventDefault();
                this.activeUid = this.flat[0]?.uid ?? null;
                this.reveal();
            } else if (event.key === 'Enter') {
                event.preventDefault();

                if (this.active) {
                    this.choose(this.active, event.metaKey || event.ctrlKey);
                }
            } else if (event.key === 'ArrowLeft' && this.chips.length > 0 && event.target.selectionStart === 0 && event.target.selectionEnd === 0) {
                event.preventDefault();
                this.focusChip(this.chips.length - 1);
            }
        },

        /** Tab moves between result groups; with no results it keeps focus inside the dialog. */
        trap(event) {
            if (event.key !== 'Tab') {
                return;
            }

            if (event.target === this.$refs.input && this.flat.length > 0) {
                event.preventDefault();
                this.moveGroup(event.shiftKey ? -1 : 1);

                return;
            }

            const focusable = [...this.$refs.panel.querySelectorAll('button:not([disabled]), input, a[href]')].filter((element) => element.offsetParent !== null);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        focusChip(index) {
            this.$nextTick(() => this.$refs.chips?.querySelectorAll('button')[index]?.focus());
        },

        chipKey(event, index) {
            if (event.key === 'ArrowLeft' && index > 0) {
                event.preventDefault();
                this.focusChip(index - 1);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                index < this.chips.length - 1 ? this.focusChip(index + 1) : this.$refs.input.focus();
            } else if (event.key === 'Backspace' || event.key === 'Delete') {
                event.preventDefault();
                this.removeChip(this.chips[index]);
            }
        },

        removeChip(chip) {
            this.query = withoutToken(this.query, chip.token);
            this.navigated = false;
            this.$refs.input.focus();
            this.search();
        },

        choose(result, newTab = false) {
            if (result.action === 'switch') {
                this.switchCompany(result);

                return;
            }

            if (result.remember) {
                fetch(config.recentUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ type: result.type, id: result.id }),
                    credentials: 'same-origin',
                    keepalive: true,
                }).catch(() => {});
            }

            if (newTab) {
                window.open(result.url, '_blank', 'noopener');

                return;
            }

            this.close();
            window.location.assign(result.url);
        },

        /** Through the same PUT route as the company menu, which checks membership again. */
        switchCompany(result) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = config.switchUrl;
            form.hidden = true;

            for (const [name, value] of Object.entries({ _method: 'PUT', _token: csrfToken(), team_id: result.payload.team_id })) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            }

            document.body.append(form);
            form.submit();
        },
    }));
});
