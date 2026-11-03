/**
 * The encrypted chat panel (ADR-045). Everything that is written is
 * encrypted here, in the browser, before it is sent; the server keeps only
 * ciphertext and deletes it when the chat ends. The code and the key live in
 * this tab's sessionStorage only, so the chat survives moving between pages
 * and is forgotten when the tab is closed.
 */

import {
    MAX_MESSAGE_LENGTH,
    cleanCode,
    decryptEvent,
    deriveSecrets,
    encryptEvent,
    followDecision,
    formatCode,
    fromBase64,
    generateCode,
    importKey,
    normalizeCode,
    safeFollowPath,
    toBase64,
} from './secure-chat-core.js';

const STORAGE_KEY = 'nonox.chat';
const FAST_POLL = 1500;
const SLOW_POLL = 5000;
const HIDDEN_CHECK = 15000;
const QUIET_AFTER = 120000;

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function readState() {
    try {
        const state = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? 'null');

        return state !== null && typeof state === 'object' ? state : {};
    } catch {
        return {};
    }
}

function writeState(state) {
    try {
        if (Object.keys(state).length === 0) {
            sessionStorage.removeItem(STORAGE_KEY);
        } else {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        }
    } catch {
        // Without sessionStorage the chat still works on this page.
    }
}

function randomId() {
    const bytes = new Uint8Array(8);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function currentPath() {
    return window.location.pathname + window.location.search;
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('secureChat', (config) => ({
        open: false,
        phase: 'idle', // idle | preparing | waiting | join | active | ended
        state: {},
        key: null,
        messages: [],
        seen: new Set(),
        since: 0,
        peer: null,
        joined: false,
        maxAt: null,
        draft: '',
        codeInput: '',
        error: '',
        notice: '',
        offer: null,
        copied: false,
        confirmingEnd: false,
        returnFocus: null,
        unread: 0,
        busy: false,
        announcement: '',
        lastActivity: Date.now(),
        timer: null,
        mine: null,
        maxLength: MAX_MESSAGE_LENGTH,
        t: config.t,

        async init() {
            window.addEventListener('chat-open', () => this.show());
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && this.key) {
                    this.schedule(0);
                }
            });
            // Livewire's wire:navigate keeps this panel (@persist); the starter's page changes all the same.
            document.addEventListener('livewire:navigated', () => this.shareCurrentPage());

            this.state = readState();

            if (typeof this.state.room === 'string' && typeof this.state.key === 'string') {
                try {
                    this.key = await importKey(fromBase64(this.state.key));
                } catch {
                    this.reset();

                    return;
                }

                this.phase = this.state.slot === 'a' && typeof this.state.code === 'string' ? 'waiting' : 'active';
                this.open = this.state.open === true;
                this.followPending();
                this.schedule(0);
            }

            this.broadcast();
        },

        get code() {
            return formatCode(this.state.code ?? '');
        },

        get isStarter() {
            return this.state.slot === 'a';
        },

        get smsHref() {
            return `sms:?&body=${encodeURIComponent(this.t.smsBody.replace(':code', this.code))}`;
        },

        get remaining() {
            return this.maxLength - this.draft.length;
        },

        get closesAt() {
            return this.maxAt ? new Date(this.maxAt * 1000).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' }) : '';
        },

        time(at) {
            return new Date(at * 1000).toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' });
        },

        show() {
            this.returnFocus = document.activeElement;
            this.open = true;
            this.unread = 0;
            this.remember({ open: true });
            this.broadcast();
            this.$nextTick(() => this.focusPanel());
        },

        hide() {
            this.open = false;
            this.remember({ open: false });
            this.$nextTick(() => this.returnFocus?.focus?.());
        },

        focusPanel() {
            const target = this.$refs.panel?.querySelector('[data-chat-focus]:not([disabled])');
            (target ?? this.$refs.panel)?.focus();
        },

        remember(changes) {
            this.state = { ...this.state, ...changes };

            if (this.state.room) {
                writeState(this.state);
            }
        },

        reset() {
            clearTimeout(this.timer);
            this.timer = null;
            this.key = null;
            this.state = {};
            this.messages = [];
            this.seen = new Set();
            this.since = 0;
            this.peer = null;
            this.joined = false;
            this.maxAt = null;
            this.offer = null;
            this.notice = '';
            writeState({});
            this.broadcast();
        },

        broadcast() {
            window.dispatchEvent(new CustomEvent('chat-state', { detail: { active: this.key !== null, unread: this.unread } }));
        },

        async request(name, body) {
            const response = await fetch(config.urls[name], {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body),
            });
            let data = {};
            try {
                data = await response.json();
            } catch {
                data = {};
            }

            return { status: response.status, data };
        },

        async start() {
            this.error = '';
            this.phase = 'preparing';

            try {
                for (let attempt = 0; attempt < 3; attempt++) {
                    const code = generateCode();
                    const { room, keyBytes } = await deriveSecrets(code);
                    const { status, data } = await this.request('start', { room });

                    if (status === 409) {
                        continue;
                    }

                    if (status !== 201) {
                        this.phase = 'idle';
                        this.error = data.message ?? this.t.failed;

                        return;
                    }

                    this.key = await importKey(keyBytes);
                    this.state = { room, key: toBase64(keyBytes), slot: 'a', code, follow: false, open: true, handledNav: 0 };
                    writeState(this.state);
                    this.apply(data);
                    this.phase = 'waiting';
                    this.broadcast();
                    this.schedule(FAST_POLL);
                    this.$nextTick(() => this.focusPanel());

                    return;
                }
            } catch {
                // Falls through to the message below.
            }

            this.phase = 'idle';
            this.error = this.t.failed;
        },

        showJoin() {
            this.error = '';
            this.codeInput = '';
            this.phase = 'join';
            this.$nextTick(() => this.focusPanel());
        },

        formatInput() {
            this.codeInput = formatCode(cleanCode(this.codeInput));
        },

        async join() {
            this.error = '';
            const code = normalizeCode(this.codeInput);

            if (code === null) {
                this.error = this.t.badCode;

                return;
            }

            this.busy = true;

            try {
                const { room, keyBytes } = await deriveSecrets(code);
                const { status, data } = await this.request('join', { room });

                if (status !== 200) {
                    this.error = data.message ?? this.t.failed;

                    return;
                }

                this.key = await importKey(keyBytes);
                this.state = { room, key: toBase64(keyBytes), slot: 'b', paused: false, open: true, handledNav: 0 };
                writeState(this.state);
                this.apply(data);
                this.phase = 'active';
                this.announcement = this.t.joinedAs.replace(':name', this.peer ?? '');
                this.broadcast();
                this.schedule(0);
                this.$nextTick(() => this.focusPanel());
            } catch {
                this.error = this.t.failed;
            } finally {
                this.busy = false;
            }
        },

        apply(data) {
            this.peer = data.peer ?? null;
            this.maxAt = data.max_at ?? null;

            if (data.joined && !this.joined) {
                this.joined = true;

                if (this.isStarter) {
                    if (typeof this.state.code === 'string') {
                        // The code has done its job: it no longer works, and is no longer kept.
                        const { code, ...rest } = this.state;
                        this.state = rest;
                        writeState(this.state);
                        this.announcement = this.t.peerJoined.replace(':name', this.peer ?? '');
                    }

                    this.phase = 'active';
                    this.shareCurrentPage();
                }
            }
        },

        schedule(delay) {
            clearTimeout(this.timer);

            if (!this.key) {
                return;
            }

            this.timer = setTimeout(() => this.poll(), delay);
        },

        nextDelay() {
            if (document.hidden) {
                return HIDDEN_CHECK;
            }

            return Date.now() - this.lastActivity > QUIET_AFTER ? SLOW_POLL : FAST_POLL;
        },

        async poll() {
            if (!this.key) {
                return;
            }

            try {
                const { status, data } = await this.request('poll', { room: this.state.room, since: this.since });

                if (status === 404) {
                    this.ended(true);

                    return;
                }

                if (status === 200) {
                    this.apply(data);
                    await this.receive(data.messages ?? []);
                    this.shareCurrentPage();
                }
            } catch {
                // The network is away for a moment; try again.
            }

            this.schedule(this.nextDelay());
        },

        async receive(list) {
            let latestNav = null;

            for (const message of list) {
                this.since = Math.max(this.since, message.seq);
                const event = await decryptEvent(this.key, this.state.room, message.from, message.iv, message.ct);

                if (event === null || (typeof event.id === 'string' && this.seen.has(event.id))) {
                    continue;
                }

                if (typeof event.id === 'string') {
                    this.seen.add(event.id);
                }

                const mine = message.from === this.state.slot;

                if (event.t === 'msg' && typeof event.body === 'string') {
                    this.messages.push({ seq: message.seq, mine, kind: 'msg', body: event.body.slice(0, MAX_MESSAGE_LENGTH), at: message.at });

                    if (!mine && message.seq > (this.state.lastSeen ?? 0)) {
                        this.lastActivity = Date.now();
                        this.announcement = this.t.incoming.replace(':name', this.peer ?? '').replace(':message', event.body.slice(0, 200));

                        if (!this.open) {
                            this.unread++;
                        }
                    }
                } else if (event.t === 'nav') {
                    const path = safeFollowPath(event.path, window.location.origin);
                    this.messages.push({ seq: message.seq, mine, kind: 'nav', body: path ?? '', at: message.at });

                    if (!mine && message.seq > (this.state.handledNav ?? 0)) {
                        latestNav = { seq: message.seq, event };
                    }
                }
            }

            if (list.length > 0) {
                this.state.lastSeen = Math.max(this.state.lastSeen ?? 0, this.since);
                writeState(this.state);
                this.broadcast();
                this.$nextTick(() => {
                    const log = this.$refs.log;
                    if (log) {
                        log.scrollTop = log.scrollHeight;
                    }
                });
            }

            if (latestNav !== null) {
                this.remember({ handledNav: latestNav.seq, lastNav: { path: latestNav.event.path, company: latestNav.event.company ?? null } });

                if (!this.state.paused) {
                    await this.follow(this.state.lastNav);
                }
            }
        },

        async send() {
            const body = this.draft.trim();

            if (body === '' || !this.key || this.busy) {
                return;
            }

            if (body.length > MAX_MESSAGE_LENGTH) {
                this.error = this.t.tooLong;

                return;
            }

            this.busy = true;
            this.error = '';

            try {
                const ok = await this.sendEvent({ t: 'msg', id: randomId(), body });

                if (ok) {
                    this.draft = '';
                    this.lastActivity = Date.now();
                    this.schedule(0);
                }
            } finally {
                this.busy = false;
                this.$nextTick(() => this.$refs.draft?.focus());
            }
        },

        async sendEvent(event) {
            const sealed = await encryptEvent(this.key, this.state.room, this.state.slot, event);
            const { status, data } = await this.request('send', { room: this.state.room, ...sealed });

            if (status === 404) {
                this.ended(true);

                return false;
            }

            if (status !== 201) {
                this.error = data.message ?? this.t.failed;

                return false;
            }

            return true;
        },

        keydown(event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                this.send();
            }
        },

        // The starter: "Följ mina sidor".
        async toggleFollow() {
            this.remember({ follow: !this.state.follow, sharedPath: null });
            this.shareCurrentPage();
        },

        async shareCurrentPage() {
            if (!this.key || !this.isStarter || !this.state.follow || !this.joined) {
                return;
            }

            const path = currentPath();

            if (this.state.sharedPath === path || safeFollowPath(path, window.location.origin) === null) {
                return;
            }

            this.remember({ sharedPath: path });

            const sent = await this.sendEvent({ t: 'nav', id: randomId(), path, company: config.company });

            if (!sent) {
                this.remember({ sharedPath: null });
            }
        },

        // The follower.
        togglePause() {
            const paused = !this.state.paused;
            this.remember({ paused });
            this.notice = '';
            this.offer = null;

            if (!paused && this.state.lastNav) {
                this.follow(this.state.lastNav);
            }
        },

        async myCompanies() {
            if (this.mine === null) {
                const { status, data } = await this.request('companies', {});
                this.mine = status === 200 ? data : { current: null, companies: [] };
            }

            return this.mine;
        },

        async follow(nav) {
            this.notice = '';
            this.offer = null;

            const decision = followDecision(nav, window.location.origin, currentPath(), await this.myCompanies());

            if (decision.action === 'denied') {
                this.notice = this.t.noAccess;
            } else if (decision.action === 'switch') {
                this.offer = decision;
            } else if (decision.action === 'navigate') {
                window.location.assign(decision.path);
            }
        },

        acceptOffer() {
            if (!this.offer) {
                return;
            }

            // After the switch the company's overview opens; from there the panel goes on to the page.
            this.remember({ pendingPath: this.offer.path });
            this.$refs.switchCompany.value = String(this.offer.company.id);
            this.$refs.switchForm.submit();
        },

        followPending() {
            const path = safeFollowPath(this.state.pendingPath, window.location.origin);

            if (this.state.pendingPath) {
                this.remember({ pendingPath: null });
            }

            if (path !== null && path !== currentPath()) {
                window.location.assign(path);
            }
        },

        async end() {
            const room = this.state.room;
            this.reset();
            this.phase = 'ended';
            this.announcement = this.t.ended;

            if (room) {
                try {
                    await this.request('end', { room });
                } catch {
                    // It is deleted by itself after the idle time.
                }
            }

            this.$nextTick(() => this.focusPanel());
        },

        ended(byOther) {
            const wasOpen = this.open;
            this.reset();
            this.phase = 'ended';
            this.announcement = this.t.ended;

            if (byOther && !wasOpen) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: this.t.ended, type: 'success' } }));
            }
        },

        restart() {
            this.phase = 'idle';
            this.error = '';
            this.$nextTick(() => this.focusPanel());
        },

        async copy() {
            try {
                await navigator.clipboard.writeText(this.code);
                this.copied = true;
                setTimeout(() => (this.copied = false), 2000);
            } catch {
                this.copied = false;
            }
        },
    }));
});
