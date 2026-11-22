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
