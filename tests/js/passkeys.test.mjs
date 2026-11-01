// The browser half of passkeys (ADR-029): base64url in and out, and the shape
// sent back to the server. Run with: npm test (node --test, no packages needed).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { base64urlToBuffer, bufferToBase64url, creationOptions, credentialToJson, requestOptions } from '../../resources/js/passkeys.js';

const bytes = (...values) => new Uint8Array(values).buffer;

test('base64url round-trips without padding or + and /', () => {
    const buffer = bytes(251, 255, 191, 0, 1);

    assert.equal(bufferToBase64url(buffer), '-_-_AAE');
    assert.deepEqual(new Uint8Array(base64urlToBuffer('-_-_AAE')), new Uint8Array(buffer));
    assert.equal(bufferToBase64url(base64urlToBuffer('')), '');
});

test('creation options get buffers for the challenge, user id and excluded credentials', () => {
    const options = creationOptions({
        challenge: 'AQID',
        rp: { id: 'localhost', name: 'localhost' },
        user: { id: 'BAU', name: 'anna@example.com', displayName: 'Anna' },
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
        excludeCredentials: [{ type: 'public-key', id: 'Bgc' }],
        authenticatorSelection: { userVerification: 'required', residentKey: 'required' },
    });

    assert.deepEqual(new Uint8Array(options.challenge), new Uint8Array([1, 2, 3]));
    assert.deepEqual(new Uint8Array(options.user.id), new Uint8Array([4, 5]));
    assert.equal(options.user.name, 'anna@example.com');
    assert.deepEqual(new Uint8Array(options.excludeCredentials[0].id), new Uint8Array([6, 7]));
    assert.equal(options.authenticatorSelection.userVerification, 'required');
});

test('request options get buffers for the challenge and allowed credentials, and accept none', () => {
    const options = requestOptions({ challenge: 'AQID', rpId: 'localhost', allowCredentials: [{ type: 'public-key', id: 'Bgc' }], userVerification: 'required' });

    assert.deepEqual(new Uint8Array(options.allowCredentials[0].id), new Uint8Array([6, 7]));
    assert.deepEqual(requestOptions({ challenge: 'AQID' }).allowCredentials, []);
});

test('an attestation goes back with its attestation object and transports', () => {
    const json = credentialToJson({
        id: 'Bgc',
        rawId: bytes(6, 7),
        type: 'public-key',
        response: { clientDataJSON: bytes(1), attestationObject: bytes(2), getTransports: () => ['internal'] },
        getClientExtensionResults: () => ({}),
    });

    assert.deepEqual(json, { id: 'Bgc', rawId: 'Bgc', type: 'public-key', response: { clientDataJSON: 'AQ', attestationObject: 'Ag', transports: ['internal'] }, clientExtensionResults: {} });
});

test('an assertion goes back with authenticator data, signature and user handle', () => {
    const json = credentialToJson({
        id: 'Bgc',
        rawId: bytes(6, 7),
        type: 'public-key',
        response: { clientDataJSON: bytes(1), authenticatorData: bytes(2), signature: bytes(3), userHandle: bytes(4) },
    });

    assert.deepEqual(json.response, { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'BA' });
    assert.deepEqual(json.clientExtensionResults, {});
});
