/**
 * The cryptography and rules of the encrypted chat (ADR-045), without the
 * page: WebCrypto only, so it runs in the browser and in Node's tests.
 *
 * The code: 10 characters of Crockford base32 (50 bits), shown as
 * XXXX-XXXX-XX. From it the browser derives, and never sends:
 *   master = PBKDF2-SHA-256(code, "nonox-chat-v1|" + origin, 600 000 iterations)
 *   room   = HKDF-SHA-256(master, info "nonox-chat-v1 room"), 32 bytes, base64url: the server's lookup key
 *   key    = HKDF-SHA-256(master, info "nonox-chat-v1 key"), an AES-256-GCM key that never leaves the browser
 * PBKDF2 makes testing every code against a room id seen on the server
 * expensive (ADR-045, threat model), and the origin in the salt means one
 * precomputed table cannot serve every NONOX installation; HKDF splits the
 * result in two.
 */

export const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
export const CODE_LENGTH = 10;
export const PBKDF2_ITERATIONS = 600000;
export const MAX_MESSAGE_LENGTH = 1000;
export const MAX_PATH_LENGTH = 1000;

const SALT = 'nonox-chat-v1';
const encoder = new TextEncoder();
const decoder = new TextDecoder();

function subtle() {
    return globalThis.crypto.subtle;
}

/** A new code: 10 characters, each 5 random bits (256 is a multiple of 32, so no bias). */
export function generateCode() {
    const bytes = new Uint8Array(CODE_LENGTH);
    globalThis.crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => ALPHABET[byte & 31]).join('');
}

/** XXXX-XXXX-XX, for reading aloud and for the input field while typing. */
export function formatCode(code) {
    const clean = String(code ?? '').slice(0, CODE_LENGTH);

    return [clean.slice(0, 4), clean.slice(4, 8), clean.slice(8, 10)].filter((part) => part !== '').join('-');
}

/**
 * What the person typed, as the canonical code: any case, with or without
 * dashes and spaces, O read as 0 and I or L as 1. Null when it is not a
 * whole valid code.
 */
export function normalizeCode(input) {
    const code = canonical(input);

    return code.length === CODE_LENGTH && [...code].every((char) => ALPHABET.includes(char)) ? code : null;
}

/** The characters typed so far, canonical, for the field's live formatting. */
export function cleanCode(input) {
    return canonical(input).replace(/[^0-9A-Z]/g, '').slice(0, CODE_LENGTH);
}

function canonical(input) {
    return String(input ?? '')
        .toUpperCase()
        .replace(/[\s\-_.]/g, '')
        .replace(/O/g, '0')
        .replace(/[IL]/g, '1');
}

export function toBase64(bytes) {
    let binary = '';
    for (const byte of new Uint8Array(bytes)) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary);
}

export function fromBase64(text) {
    const binary = atob(text);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
}

export function toBase64Url(bytes) {
    return toBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * The room id and the raw key bytes for a canonical code. Deterministic: the
 * same code always gives the same pair, on both sides.
 *
 * @returns {Promise<{room: string, keyBytes: Uint8Array}>}
 */
export async function deriveSecrets(code, origin, iterations = PBKDF2_ITERATIONS) {
    if (typeof origin !== 'string' || origin === '') {
        throw new TypeError('The origin is part of the salt.');
    }

    const password = await subtle().importKey('raw', encoder.encode(code), 'PBKDF2', false, ['deriveBits']);
    const master = await subtle().deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: encoder.encode(`${SALT}|${origin}`), iterations }, password, 256);
    const hkdf = await subtle().importKey('raw', master, 'HKDF', false, ['deriveBits']);
    const expand = (info) => subtle().deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(32), info: encoder.encode(`${SALT} ${info}`) }, hkdf, 256);

    const [room, key] = await Promise.all([expand('room'), expand('key')]);

    return { room: toBase64Url(room), keyBytes: new Uint8Array(key) };
}

export function importKey(keyBytes) {
    return subtle().importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
}

/**
 * Binds a message to its chat and its sender: the server stores the sender's
 * slot beside the ciphertext, and if it changed it, decryption fails.
 */
function additionalData(room, slot) {
    return encoder.encode(`${SALT}|${room}|${slot}`);
}

/**
 * Encrypts one event ({t: 'msg', id, n, body} or {t: 'nav', id, n, path,
 * company}) with a fresh 96-bit IV. `id` is random and `n` counts the
 * sender's events from 1, so the receiver can spot a replay and a gap.
 */
export async function encryptEvent(key, room, slot, event) {
    const iv = new Uint8Array(12);
    globalThis.crypto.getRandomValues(iv);
    const plaintext = encoder.encode(JSON.stringify(event));
    const ciphertext = await subtle().encrypt({ name: 'AES-GCM', iv, additionalData: additionalData(room, slot) }, key, plaintext);

    return { iv: toBase64(iv), ct: toBase64(ciphertext) };
}

/** The event, or null when it was changed, belongs to another chat or sender, or is not ours. */
export async function decryptEvent(key, room, slot, iv, ct) {
    try {
        const plaintext = await subtle().decrypt({ name: 'AES-GCM', iv: fromBase64(iv), additionalData: additionalData(room, slot) }, key, fromBase64(ct));
        const event = JSON.parse(decoder.decode(plaintext));

        return event !== null && typeof event === 'object' && typeof event.t === 'string' ? event : null;
    } catch {
        return null;
    }
}

/**
 * Paths that must never be opened from the chat, even on the user's own
 * NONOX: files, downloads, callbacks, exports of personal data, invitations,
 * kiosks and sign-in. The same rules as App\Support\Chat\FollowablePath,
 * which decides on the server with an allowlist; this is only the first line.
 */
const BLOCKED_PATHS = [
    /\.[a-z0-9]{2,5}$/i,
    /\/(fil|pdf|csv|sru|sie-fil|lonefil|faktura|kvitto|original|foto|qr|qr-kort|logotyp|byrans-logotyp|provsida|arbetsgivarintyg|klar|data|ikon|manifest|serviceworker)$/i,
    /\/(bilagor|underlag|arkivpaket|betalfiler)\//i,
    /^\/(registerutdrag|foretagsinbjudningar|anslut|oauth|api|livewire|chatt|hq|logga-ut|logga-in|aktuellt-foretag|varumarke|sanctum|mina-uppgifter)(\/|$)/i,
    /^\/(bank|flytt\/fortnox)\//i,
    /^\/profil\/mina-uppgifter(\/|$)/i,
    /\/kiosk(\/|$)/i,
];

/**
 * The same-origin path to follow, or null. The channel is encrypted, but the
 * other person decides what is sent: only a plain path on this site, never a
 * host, a scheme or a route that changes something.
 */
export function safeFollowPath(path, origin) {
    if (typeof path !== 'string' || path.length === 0 || path.length > MAX_PATH_LENGTH) {
        return null;
    }

    if (!path.startsWith('/') || path.startsWith('//') || /[\\\s\u0000-\u001f\u007f]/.test(path)) {
        return null;
    }

    let url;
    try {
        url = new URL(path, origin);
    } catch {
        return null;
    }

    if (url.origin !== new URL(origin).origin) {
        return null;
    }

    if (BLOCKED_PATHS.some((pattern) => pattern.test(url.pathname))) {
        return null;
    }

    return url.pathname + url.search;
}

/**
 * What the follower does with a page the starter opened: go there, offer to
 * switch company first, or say it is out of reach.
 *
 * @returns {{action: 'navigate'|'switch'|'denied'|'ignore', path?: string, company?: {id: number, name: string}}}
 */
export function followDecision(event, origin, currentPath, mine) {
    const path = safeFollowPath(event?.path, origin);

    if (path === null) {
        return { action: 'denied' };
    }

    const companyId = Number.isInteger(event.company) ? event.company : null;

    if (companyId !== null && companyId !== mine.current) {
        const company = (mine.companies ?? []).find((candidate) => candidate.id === companyId);

        return company ? { action: 'switch', path, company } : { action: 'denied' };
    }

    return path === currentPath ? { action: 'ignore', path } : { action: 'navigate', path };
}

/**
 * Whether a decrypted event is new, the same one fetched again (after a
 * reload, at the same sequence number), or a replay: the same event id
 * stored again under another sequence number by the server.
 *
 * @param {Record<string, number>} seen event id => sequence number it was first seen at
 * @returns {'new'|'same'|'replay'}
 */
export function seenStatus(seen, id, seq) {
    if (typeof id !== 'string' || !(id in seen)) {
        return 'new';
    }

    return seen[id] === seq ? 'same' : 'replay';
}

/**
 * Checks a sender's counter: the next one is 'ok', a jump means the server
 * held back at least one event ('gap'), and one already seen is 'old'.
 *
 * @param {Record<string, number>} counters slot => highest counter seen
 * @returns {'ok'|'gap'|'old'|'invalid'}
 */
export function counterStatus(counters, slot, n) {
    if (!Number.isInteger(n) || n < 1) {
        return 'invalid';
    }

    const last = counters[slot] ?? 0;

    if (n <= last) {
        return 'old';
    }

    return n === last + 1 ? 'ok' : 'gap';
}
