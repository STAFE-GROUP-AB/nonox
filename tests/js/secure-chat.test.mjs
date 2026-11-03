import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    ALPHABET,
    CODE_LENGTH,
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
} from '../../resources/js/secure-chat-core.js';

const ORIGIN = 'https://nonox.example';

test('a code is 10 characters of Crockford base32: 50 bits, no ambiguous letters', () => {
    assert.equal(ALPHABET.length, 32);
    assert.equal(CODE_LENGTH * Math.log2(ALPHABET.length), 50);
    assert.doesNotMatch(ALPHABET, /[ILOU]/);

    for (let i = 0; i < 200; i++) {
        const code = generateCode();
        assert.match(code, /^[0-9A-HJKMNP-TV-Z]{10}$/);
    }
});

test('codes are spread over the whole alphabet and practically never repeat', () => {
    const seen = new Set();
    const counts = new Map();

    for (let i = 0; i < 2000; i++) {
        const code = generateCode();
        seen.add(code);
        for (const char of code) {
            counts.set(char, (counts.get(char) ?? 0) + 1);
        }
    }

    assert.equal(seen.size, 2000);
    assert.equal(counts.size, 32);
    // 20 000 characters over 32 symbols: about 625 each; a biased generator would show far outside this.
    for (const count of counts.values()) {
        assert.ok(count > 450 && count < 800, `count ${count}`);
    }
});

test('the code is shown in groups and read back in any form', () => {
    assert.equal(formatCode('K7Q4XM9PD2'), 'K7Q4-XM9P-D2');
    assert.equal(formatCode('K7Q4X'), 'K7Q4-X');
    assert.equal(normalizeCode('K7Q4-XM9P-D2'), 'K7Q4XM9PD2');
    assert.equal(normalizeCode(' k7q4 xm9p d2 '), 'K7Q4XM9PD2');
    assert.equal(normalizeCode('k7q4_xm9p.d2'), 'K7Q4XM9PD2');
    // O is read as zero, I and L as one.
    assert.equal(normalizeCode('OOII-LL00-11'), '0011110011');
    assert.equal(cleanCode('ab-c'), 'ABC');
});

test('incomplete or foreign codes are refused', () => {
    assert.equal(normalizeCode('K7Q4-XM9P-D'), null);
    assert.equal(normalizeCode('K7Q4-XM9P-D22'), null);
    assert.equal(normalizeCode('K7Q4-XM9P-D!'), null);
    assert.equal(normalizeCode('UUUU-UUUU-UU'), null);
    assert.equal(normalizeCode(''), null);
    assert.equal(normalizeCode(null), null);
});

test('the same code gives the same room id and key on both sides; another code differs', async () => {
    const one = await deriveSecrets('K7Q4XM9PD2', 1000);
    const again = await deriveSecrets('K7Q4XM9PD2', 1000);
    const other = await deriveSecrets('K7Q4XM9PD3', 1000);

    assert.match(one.room, /^[A-Za-z0-9_-]{43}$/);
    assert.equal(one.keyBytes.length, 32);
    assert.equal(one.room, again.room);
    assert.deepEqual(one.keyBytes, again.keyBytes);
    assert.notEqual(one.room, other.room);
    assert.notDeepEqual(one.keyBytes, other.keyBytes);
    // The room id the server sees is not the key.
    assert.notDeepEqual(Buffer.from(one.room.replace(/-/g, '+').replace(/_/g, '/'), 'base64'), Buffer.from(one.keyBytes));
});

test('the real derivation uses 600 000 PBKDF2 iterations and is stable', async () => {
    const { room } = await deriveSecrets('0000000000');
    const { room: same } = await deriveSecrets('0000000000');

    assert.equal(room, same);
});

test('a message survives the round trip and every byte is protected', async () => {
    const { room, keyBytes } = await deriveSecrets('K7Q4XM9PD2', 1000);
    const key = await importKey(keyBytes);
    const event = { t: 'msg', id: 'abc', body: 'Hej! Åäö 😀' };

    const sealed = await encryptEvent(key, room, 'a', event);
    assert.equal(fromBase64(sealed.iv).length, 12);
    assert.ok(!sealed.ct.includes('Hej'));
    assert.deepEqual(await decryptEvent(key, room, 'a', sealed.iv, sealed.ct), event);

    // A flipped bit, another sender slot, another chat or another key: nothing comes out.
    const bytes = fromBase64(sealed.ct);
    bytes[0] ^= 1;
    const tampered = Buffer.from(bytes).toString('base64');
    assert.equal(await decryptEvent(key, room, 'a', sealed.iv, tampered), null);
    assert.equal(await decryptEvent(key, room, 'b', sealed.iv, sealed.ct), null);
    assert.equal(await decryptEvent(key, 'x'.repeat(43), 'a', sealed.iv, sealed.ct), null);
    const otherKey = await importKey((await deriveSecrets('K7Q4XM9PD3', 1000)).keyBytes);
    assert.equal(await decryptEvent(otherKey, room, 'a', sealed.iv, sealed.ct), null);
});

test('every message gets a fresh IV', async () => {
    const { room, keyBytes } = await deriveSecrets('K7Q4XM9PD2', 1000);
    const key = await importKey(keyBytes);
    const ivs = new Set();

    for (let i = 0; i < 50; i++) {
        ivs.add((await encryptEvent(key, room, 'a', { t: 'msg', body: 'samma' })).iv);
    }

    assert.equal(ivs.size, 50);
});

test('only plain paths on this site are followed', () => {
    assert.equal(safeFollowPath('/verifikationer?sida=2', ORIGIN), '/verifikationer?sida=2');
    assert.equal(safeFollowPath('/kundfakturor/12', ORIGIN), '/kundfakturor/12');

    for (const bad of ['https://evil.example/', '//evil.example/x', 'javascript:alert(1)', '/\\evil.example', 'verifikationer', '/a b', '/x\n', '', null, 42, '/' + 'a'.repeat(1001)]) {
        assert.equal(safeFollowPath(bad, ORIGIN), null, String(bad));
    }

    for (const blocked of ['/logga-ut', '/logga-in', '/logga-in/passkey', '/chatt/avsluta', '/aktuellt-foretag', '/livewire/update', '/api/v1/vouchers']) {
        assert.equal(safeFollowPath(blocked, ORIGIN), null, blocked);
    }
});

test('the follower goes along, is offered a switch, or is told it is out of reach', () => {
    const mine = { current: 1, companies: [{ id: 1, name: 'Ett AB' }, { id: 2, name: 'Två AB' }] };

    assert.deepEqual(followDecision({ t: 'nav', path: '/kontoplan', company: 1 }, ORIGIN, '/oversikt', mine), { action: 'navigate', path: '/kontoplan' });
    assert.deepEqual(followDecision({ t: 'nav', path: '/kontoplan', company: 1 }, ORIGIN, '/kontoplan', mine), { action: 'ignore', path: '/kontoplan' });
    assert.deepEqual(followDecision({ t: 'nav', path: '/kontoplan', company: 2 }, ORIGIN, '/oversikt', mine), { action: 'switch', path: '/kontoplan', company: { id: 2, name: 'Två AB' } });
    assert.deepEqual(followDecision({ t: 'nav', path: '/kontoplan', company: 3 }, ORIGIN, '/oversikt', mine), { action: 'denied' });
    assert.deepEqual(followDecision({ t: 'nav', path: '/profil', company: null }, ORIGIN, '/oversikt', mine), { action: 'navigate', path: '/profil' });
    assert.deepEqual(followDecision({ t: 'nav', path: 'https://evil.example', company: 1 }, ORIGIN, '/oversikt', mine), { action: 'denied' });
});
