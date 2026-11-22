import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { test } from 'node:test';
import { hashFile, hashFromFragment, isSha256, requestBody, toHex } from '../../resources/js/verify-core.js';

const ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

test('hashes a file with SHA-256 as lowercase hex', async () => {
    assert.equal(await hashFile(new Blob(['abc']), webcrypto.subtle), ABC);
});

test('hashes the empty file and a file of binary bytes', async () => {
    assert.equal(await hashFile(new Blob([]), webcrypto.subtle), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
    assert.equal(isSha256(await hashFile(new Blob([new Uint8Array([0, 255, 128])]), webcrypto.subtle)), true);
});

test('refuses without Web Crypto or without a readable file', async () => {
    await assert.rejects(hashFile(new Blob(['x']), null), /NO_WEB_CRYPTO/);
    await assert.rejects(hashFile({}, webcrypto.subtle), /NO_WEB_CRYPTO/);
});

test('toHex pads every byte to two characters', () => {
    assert.equal(toHex(new Uint8Array([0, 1, 15, 16, 255]).buffer), '00010f10ff');
});

test('accepts exactly 64 lowercase hex characters', () => {
    assert.equal(isSha256(ABC), true);
    assert.equal(isSha256(ABC.toUpperCase()), false);
    assert.equal(isSha256(`${ABC}0`), false);
    assert.equal(isSha256(ABC.slice(1)), false);
    assert.equal(isSha256(`${ABC.slice(1)}g`), false);
    assert.equal(isSha256(`${ABC}\n`), false);
    assert.equal(isSha256(null), false);
    assert.equal(isSha256(12), false);
});

test('reads the hash from the fragment of the certificate link', () => {
    assert.equal(hashFromFragment(`#sha256=${ABC}`), ABC);
    assert.equal(hashFromFragment(`sha256=${ABC}`), ABC);
    assert.equal(hashFromFragment('#sha256=abc'), null);
    assert.equal(hashFromFragment(`#sha256=${ABC}&x=1`), null);
    assert.equal(hashFromFragment(`#other=${ABC}`), null);
    assert.equal(hashFromFragment(''), null);
    assert.equal(hashFromFragment(undefined), null);
});

test('the request body holds only the hash', () => {
    assert.deepEqual(JSON.parse(requestBody(ABC)), { sha256: ABC });
});
