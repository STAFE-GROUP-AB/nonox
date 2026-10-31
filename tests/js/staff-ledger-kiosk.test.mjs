// The kiosk's offline logic (ADR-022). The fixed event below is also checked
// in tests/Feature/StaffLedger/KioskSyncTest.php, so the browser's hash chain
// and the server's recomputation agree. Run with: npm test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    applyAck, canonicalEvent, chainHash, createEvent, crc32, csv, MAX_PIN_ATTEMPTS, nextDirection,
    pinLockState, pinMatches, pinVerifier, presentSince, registerPinFailure, sendOrder, zip,
} from '../../app/Modules/StaffLedger/resources/js/kiosk-core.js';

const fixed = {
    uuid: '6f1c2a3e-0b9d-4c55-9a77-2f0e4d1b8c01',
    person_id: 7,
    location_id: 3,
    direction: 'IN',
    method: 'KIOSK_PIN',
    occurred_at: '2026-11-02T08:02:05+01:00',
    sequence: 1,
};

const FIXED_HASH = 'acecf47a593bf3e1daf52e54709e304a96bd30eabac9e2d93b8669403d534762';

test('the canonical form has the keys in order', () => {
    assert.equal(canonicalEvent(fixed), '{"direction":"IN","location_id":3,"method":"KIOSK_PIN","occurred_at":"2026-11-02T08:02:05+01:00","person_id":7,"sequence":1,"uuid":"6f1c2a3e-0b9d-4c55-9a77-2f0e4d1b8c01"}');
});

test('the chain hash matches the server (sha256 of previous hash and canonical JSON)', async () => {
    const first = await chainHash(null, fixed);
    assert.equal(first, FIXED_HASH);
    assert.equal(await chainHash('', fixed), first);
    assert.notEqual(await chainHash(first, { ...fixed, sequence: 2 }), first);
});

test('a new event is numbered after the last and linked to it', async () => {
    const event = await createEvent({ uuid: fixed.uuid, personId: 7, locationId: 3, direction: 'OUT', method: 'KIOSK_QR', now: new Date(), lastSequence: 41, lastHash: 'abc' });

    assert.equal(event.sequence, 42);
    assert.equal(event.previous_client_hash, 'abc');
    assert.equal(event.client_hash, await chainHash('abc', event));
    assert.match(event.occurred_at, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
});

test('the PIN verifier is PBKDF2-SHA256 like the server', async () => {
    const salt = '00112233445566778899aabbccddeeff';
    const verifier = await pinVerifier('2580', salt, 1000);

    assert.match(verifier, /^[0-9a-f]{64}$/);
    assert.equal(await pinMatches('2580', { pin_salt: salt, pin_verifier: verifier }, 1000), true);
    assert.equal(await pinMatches('2581', { pin_salt: salt, pin_verifier: verifier }, 1000), false);
    assert.equal(await pinMatches('25', { pin_salt: salt, pin_verifier: verifier }, 1000), false);
    // The same value as Pin::verifier('2580', salt) in KioskSyncTest.php, at the real iteration count.
    assert.equal(await pinVerifier('2580', salt, 100000), 'd167fda4230a3fd774345ac86bcc02b9eb9e97c18db13fdd31c8d6084e0eb475');
});

test('wrong PINs lock the person out for a while', () => {
    let attempts = null;

    for (let i = 0; i < MAX_PIN_ATTEMPTS; i++) {
        attempts = registerPinFailure(attempts, 1000 + i);
    }

    assert.equal(pinLockState(attempts, 2000).locked, true);
    assert.equal(pinLockState(attempts, 2000 + 5 * 60 * 1000).locked, false);
});

test('the queue is sent oldest first and the answer removes what the server has', () => {
    const queue = [{ sequence: 3 }, { sequence: 1 }, { sequence: 2 }, { sequence: 4 }];

    assert.deepEqual(sendOrder(queue).map((e) => e.sequence), [1, 2, 3, 4]);

    const { remaining, rejected } = applyAck(queue, { accepted: [1, 3], duplicates: [2], rejected: [{ sequence: 4, reason: 'unknown_person' }] });
    assert.deepEqual(remaining, []);
    assert.deepEqual(rejected, [{ sequence: 4, reason: 'unknown_person' }]);
    assert.deepEqual(applyAck(queue, { accepted: [1] }).remaining.map((e) => e.sequence), [3, 2, 4]);
});

test('whether someone is in follows the newest queued event, offline too', () => {
    const person = { id: 7, present_since: null };

    assert.equal(nextDirection(person, []), 'IN');
    assert.equal(presentSince(person, [{ person_id: 7, sequence: 1, direction: 'IN', occurred_at: 'x' }]), 'x');
    assert.equal(nextDirection(person, [{ person_id: 7, sequence: 1, direction: 'IN' }, { person_id: 7, sequence: 2, direction: 'OUT' }]), 'IN');
    assert.equal(nextDirection({ id: 7, present_since: '2026-11-02T08:00:00+01:00' }, []), 'OUT');
});

test('the USB export is a valid ZIP with a safe CSV', () => {
    assert.equal(crc32(new TextEncoder().encode('123456789')), 0xcbf43926);

    const archive = zip([{ name: 'a.txt', content: 'hej' }, { name: 'å.csv', content: csv([['=1+1', '-12,50', 'Åsa']]) }]);
    const view = new DataView(archive.buffer);

    assert.equal(view.getUint32(0, true), 0x04034b50);
    assert.equal(view.getUint32(archive.length - 22, true), 0x06054b50);
    assert.equal(view.getUint16(archive.length - 12, true), 2);
    assert.equal(csv([['=1+1', '-12,50', 'a;b']]), '﻿\'=1+1;-12,50;"a;b"\r\n');
});
