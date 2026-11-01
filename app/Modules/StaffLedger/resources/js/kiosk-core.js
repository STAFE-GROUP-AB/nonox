// The kiosk's pure logic (ADR-022), shared by the browser and node --test:
// the device's own hash chain over queued events, PIN checks with PBKDF2,
// the order events are sent in, and a small ZIP writer for the USB export.
// It uses only WebCrypto (globalThis.crypto), which browsers and Node have.

const encoder = new TextEncoder();

export function toHex(buffer) {
    return [...new Uint8Array(buffer)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function fromHex(hex) {
    const bytes = new Uint8Array(hex.length / 2);

    for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(hex.substr(i * 2, 2), 16);
    }

    return bytes;
}

export async function sha256(text) {
    return toHex(await globalThis.crypto.subtle.digest('SHA-256', encoder.encode(text)));
}

/**
 * The canonical form the server recomputes (RecordKioskEvents::clientCanonical):
 * these fields, keys in alphabetical order, as JSON.
 */
export function canonicalEvent(event) {
    return JSON.stringify({
        direction: event.direction,
        location_id: event.location_id,
        method: event.method,
        occurred_at: event.occurred_at,
        person_id: event.person_id,
        sequence: event.sequence,
        uuid: event.uuid,
    });
}

/** client_hash = sha256(previous_client_hash || canonical_json(event)). */
export async function chainHash(previousHash, event) {
    return sha256((previousHash ?? '') + canonicalEvent(event));
}

/**
 * A new event, numbered after the device's last one and linked into its chain.
 * The time is the device's own, with its offset, to the second.
 */
export async function createEvent({ uuid, personId, locationId, direction, method, now, lastSequence, lastHash }) {
    const event = {
        uuid,
        person_id: personId,
        location_id: locationId,
        direction,
        method,
        occurred_at: isoWithOffset(now),
        sequence: lastSequence + 1,
        previous_client_hash: lastHash ?? null,
    };

    event.client_hash = await chainHash(lastHash, event);

    return event;
}

/** 2026-11-02T08:02:05+01:00 */
export function isoWithOffset(date) {
    const pad = (value) => String(Math.abs(value)).padStart(2, '0');
    const offset = -date.getTimezoneOffset();
    const sign = offset >= 0 ? '+' : '-';

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}${sign}${pad(Math.trunc(offset / 60))}:${pad(offset % 60)}`;
}

/** PBKDF2-SHA256, 32 bytes, as hex: the same as Pin::verifier on the server. */
export async function pinVerifier(pin, saltHex, iterations) {
    const key = await globalThis.crypto.subtle.importKey('raw', encoder.encode(pin), 'PBKDF2', false, ['deriveBits']);
    const bits = await globalThis.crypto.subtle.deriveBits({ name: 'PBKDF2', salt: fromHex(saltHex), iterations, hash: 'SHA-256' }, key, 256);

    return toHex(bits);
}

export async function pinMatches(pin, person, iterations) {
    if (!/^\d{4}$/.test(pin) || !person.pin_salt || !person.pin_verifier) {
        return false;
    }

    return (await pinVerifier(pin, person.pin_salt, iterations)) === person.pin_verifier;
}

/** Wrong PINs per person before the kiosk locks that person out, and for how long. */
export const MAX_PIN_ATTEMPTS = 5;
export const PIN_LOCKOUT_MS = 5 * 60 * 1000;

export function pinLockState(attempts, now) {
    if (!attempts || attempts.count < MAX_PIN_ATTEMPTS) {
        return { locked: false, remainingMs: 0 };
    }

    const remainingMs = attempts.lastAt + PIN_LOCKOUT_MS - now;

    return remainingMs > 0 ? { locked: true, remainingMs } : { locked: false, remainingMs: 0 };
}

export function registerPinFailure(attempts, now) {
    const current = pinLockState(attempts, now).locked || !attempts || now - attempts.lastAt > PIN_LOCKOUT_MS ? { count: 0 } : attempts;

    return { count: (current.count ?? 0) + 1, lastAt: now };
}

/** Queued events are always sent oldest first, in sequence order. */
export function sendOrder(queue) {
    return [...queue].sort((a, b) => a.sequence - b.sequence);
}

/**
 * The queue after the server's answer: accepted and duplicate events leave
 * it, rejected ones move aside (kept for the USB export), the rest stay.
 */
export function applyAck(queue, ack) {
    const done = new Set([...(ack.accepted ?? []), ...(ack.duplicates ?? [])]);
    const rejected = new Map((ack.rejected ?? []).map((item) => [item.sequence, item.reason]));

    return {
        remaining: queue.filter((event) => !done.has(event.sequence) && !rejected.has(event.sequence)),
        rejected: queue.filter((event) => rejected.has(event.sequence)).map((event) => ({ ...event, reason: rejected.get(event.sequence) })),
    };
}

/**
 * Whether the person is in now, from the server's last word and the events
 * still waiting in the queue: the newest event wins.
 */
export function presentSince(person, queue) {
    const own = sendOrder(queue).filter((event) => event.person_id === person.id);
    const last = own[own.length - 1];

    if (!last) {
        return person.present_since ?? null;
    }

    return last.direction === 'IN' ? last.occurred_at : null;
}

/**
 * The roster's people after the server accepted these events, so leaving the
 * queue does not bring back the presence the roster was downloaded with.
 */
export function applyToPeople(people, events) {
    const latest = new Map(sendOrder(events).map((event) => [event.person_id, event]));

    return people.map((person) => {
        const event = latest.get(person.id);

        return event ? { ...person, present_since: event.direction === 'IN' ? event.occurred_at : null } : person;
    });
}

export function nextDirection(person, queue) {
    return presentSince(person, queue) ? 'OUT' : 'IN';
}

// ZIP (stored, no compression) for the USB export, readable everywhere.

const crcTable = (() => {
    const table = new Uint32Array(256);

    for (let n = 0; n < 256; n++) {
        let c = n;

        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }

        table[n] = c >>> 0;
    }

    return table;
})();

export function crc32(bytes) {
    let crc = 0xffffffff;

    for (const byte of bytes) {
        crc = crcTable[(crc ^ byte) & 0xff] ^ (crc >>> 8);
    }

    return (crc ^ 0xffffffff) >>> 0;
}

/** files: [{ name, content (string) }] → Uint8Array with a ZIP archive. */
export function zip(files) {
    const parts = [];
    const central = [];
    let offset = 0;

    for (const file of files) {
        const name = encoder.encode(file.name);
        const data = encoder.encode(file.content);
        const crc = crc32(data);
        const local = new DataView(new ArrayBuffer(30));

        local.setUint32(0, 0x04034b50, true);
        local.setUint16(4, 20, true);
        local.setUint16(6, 0x0800, true);
        local.setUint32(14, crc, true);
        local.setUint32(18, data.length, true);
        local.setUint32(22, data.length, true);
        local.setUint16(26, name.length, true);
        parts.push(new Uint8Array(local.buffer), name, data);

        const entry = new DataView(new ArrayBuffer(46));
        entry.setUint32(0, 0x02014b50, true);
        entry.setUint16(4, 20, true);
        entry.setUint16(6, 20, true);
        entry.setUint16(8, 0x0800, true);
        entry.setUint32(16, crc, true);
        entry.setUint32(20, data.length, true);
        entry.setUint32(24, data.length, true);
        entry.setUint16(28, name.length, true);
        entry.setUint32(42, offset, true);
        central.push(new Uint8Array(entry.buffer), name);

        offset += 30 + name.length + data.length;
    }

    const centralSize = central.reduce((sum, part) => sum + part.length, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, files.length, true);
    end.setUint16(10, files.length, true);
    end.setUint32(12, centralSize, true);
    end.setUint32(16, offset, true);

    const all = [...parts, ...central, new Uint8Array(end.buffer)];
    const out = new Uint8Array(all.reduce((sum, part) => sum + part.length, 0));
    let position = 0;

    for (const part of all) {
        out.set(part, position);
        position += part.length;
    }

    return out;
}

/** A CSV cell that a spreadsheet will not run as a formula (as App\Support\Csv). */
export function csvCell(value) {
    const text = value === null || value === undefined ? '' : String(value);
    const safe = /^[=+\-@\t\r]/.test(text) && !/^-?[\d\s.,]+$/.test(text) ? `'${text}` : text;

    return /[";\n]/.test(safe) ? `"${safe.replaceAll('"', '""')}"` : safe;
}

export function csv(rows) {
    return '﻿' + rows.map((row) => row.map(csvCell).join(';')).join('\r\n') + '\r\n';
}
