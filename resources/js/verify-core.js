/*
 * The Verify page's logic without the DOM (ADR-046 amendment, gap 6, S12), so
 * it can be tested in Node: the SHA-256 of a file with Web Crypto, the check
 * that a string is exactly 64 lowercase hex characters, and reading the hash
 * from the link's fragment (#sha256=…), which never travels to the server.
 */

const HEX64 = /^[0-9a-f]{64}$/;

/** True for exactly 64 lowercase hexadecimal characters, nothing else. */
export function isSha256(value) {
    return typeof value === 'string' && HEX64.test(value);
}

/** Bytes as lowercase hexadecimal. */
export function toHex(buffer) {
    return Array.from(new Uint8Array(buffer), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** The SHA-256 of a file's bytes, computed in the browser. `subtle` is crypto.subtle (injectable for tests). */
export async function hashFile(file, subtle = globalThis.crypto?.subtle) {
    if (!subtle || typeof file?.arrayBuffer !== 'function') {
        throw new Error('NO_WEB_CRYPTO');
    }

    return toHex(await subtle.digest('SHA-256', await file.arrayBuffer()));
}

/** The hash in a fragment such as "#sha256=ab12…", or null (also for upper case or any other length). */
export function hashFromFragment(fragment) {
    const match = /^#?sha256=([^&]*)$/.exec(String(fragment ?? ''));

    return match && isSha256(match[1]) ? match[1] : null;
}

/** The request body: only the hash, as JSON. */
export function requestBody(hash) {
    return JSON.stringify({ sha256: hash });
}

/** The words under a result: what it says about a chosen file, or about the hash in a link. */
export function resultNote(status, source, text) {
    if (source === 'link') {
        return status === 'none' ? '' : text.fromLink;
    }

    if (status === 'one') {
        return text.fileNote;
    }

    return status === 'many' ? text.fileMany : '';
}

/** "Filen är oförändrad" only when a chosen file matched exactly one signing; otherwise the server's neutral heading. */
export function resultHeading(status, source, serverHeading, text) {
    return source === 'file' && status === 'one' ? text.unchanged : serverHeading;
}

/** A counter that tells whether an answer is still for the latest choice. */
export function latestGuard() {
    let current = 0;

    return {
        next: () => ++current,
        isCurrent: (ticket) => ticket === current,
    };
}
