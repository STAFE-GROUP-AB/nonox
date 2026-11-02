<?php

declare(strict_types=1);

use App\Modules\Pos\Support\Hawk;
use App\Modules\Pos\Support\Pkce;

/*
 * Hawk signing (ADR-042) against the examples published with the Hawk
 * protocol (credentials id "dh37fgj492je", ts 1353832234, nonce "j4h3g2"),
 * and PKCE S256 against RFC 7636 Appendix B.
 */

const HAWK_KEY = 'werxhqb98rpaxn39848xrunpaw3489ruxnpa98w4rxn';

it('computes the protocol example MAC for a GET', function () {
    expect(Hawk::mac(HAWK_KEY, 1353832234, 'j4h3g2', 'GET', '/resource/1?b=1&a=2', 'example.com', 8000, '', 'some-app-ext-data'))
        ->toBe('6R4rV5iE+NPoym+WwjeHzjAGXUtLNIxmo1vpMofpLAE=');
});

it('computes the protocol example payload hash and MAC for a POST', function () {
    $hash = Hawk::payloadHash('Thank you for flying Hawk', 'text/plain');

    expect($hash)->toBe('Yi9LfIIFRtBEPt74PVmbTF/xVAwPn7ub15ePICfgnuY=')
        ->and(Hawk::payloadHash('Thank you for flying Hawk', 'text/plain; charset=utf-8'))->toBe($hash)
        ->and(Hawk::mac(HAWK_KEY, 1353832234, 'j4h3g2', 'POST', '/resource/1?b=1&a=2', 'example.com', 8000, $hash, 'some-app-ext-data'))
        ->toBe('aSe1DERmZuRl3pI36/9BdZmnErTw3sNzOOAUlfeKjVw=');
});

it('builds the header the protocol shows', function () {
    $header = Hawk::header('dh37fgj492je', HAWK_KEY, 'POST', 'http://example.com:8000/resource/1?b=1&a=2', 'Thank you for flying Hawk', 'text/plain', 1353832234, 'j4h3g2', 'some-app-ext-data');

    expect($header)->toBe('Hawk id="dh37fgj492je", ts="1353832234", nonce="j4h3g2", hash="Yi9LfIIFRtBEPt74PVmbTF/xVAwPn7ub15ePICfgnuY=", ext="some-app-ext-data", mac="aSe1DERmZuRl3pI36/9BdZmnErTw3sNzOOAUlfeKjVw="');
});

it('uses port 443 for https without a port', function () {
    $header = Hawk::header('id', 'key', 'GET', 'https://api.example.com/v1/~demo/products/?o=0;c=500', null, null, 1700000000, 'abc');
    $parsed = Hawk::parse($header);

    expect($parsed['mac'] ?? null)->toBe(Hawk::mac('key', 1700000000, 'abc', 'GET', '/v1/~demo/products/?o=0;c=500', 'api.example.com', 443));
});

it('verifies a valid header and refuses a bad MAC, a changed payload, an old clock and a missing hash', function () {
    $url = 'http://example.com:8000/resource/1?b=1&a=2';
    $header = Hawk::header('dh37fgj492je', HAWK_KEY, 'POST', $url, 'Thank you for flying Hawk', 'text/plain', 1353832234, 'j4h3g2', 'some-app-ext-data');

    expect(Hawk::verify($header, HAWK_KEY, 'POST', $url, 'Thank you for flying Hawk', 'text/plain', 1353832240))->toBe(['id' => 'dh37fgj492je', 'nonce' => 'j4h3g2', 'ts' => 1353832234])
        ->and(Hawk::verify($header, 'another key', 'POST', $url, 'Thank you for flying Hawk', 'text/plain', 1353832240))->toBeNull()
        ->and(Hawk::verify($header, HAWK_KEY, 'POST', $url, 'Thank you for flying hawks', 'text/plain', 1353832240))->toBeNull()
        ->and(Hawk::verify($header, HAWK_KEY, 'POST', $url, 'Thank you for flying Hawk', 'text/plain', 1353832234 + 61))->toBeNull()
        ->and(Hawk::verify(Hawk::header('dh37fgj492je', HAWK_KEY, 'POST', $url, null, null, 1353832234, 'j4h3g2'), HAWK_KEY, 'POST', $url, 'body', 'text/plain', 1353832234))->toBeNull()
        ->and(Hawk::verify('Bearer x', HAWK_KEY, 'POST', $url, null, null, 1353832234))->toBeNull();
});

it('derives the PKCE challenge of RFC 7636 Appendix B', function () {
    expect(Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM')
        ->and(Pkce::verifier())->toMatch('/^[A-Za-z0-9_-]{43}$/');
});
