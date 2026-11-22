<?php

declare(strict_types=1);

use App\Domain\Signing\Providers\Csc\CscProvider;
use App\Domain\Signing\Providers\ProviderCertificate;
use App\Domain\Signing\Providers\ProviderFailure;
use App\Domain\Signing\Providers\ProviderRun;
use App\Domain\Signing\Providers\ProviderStep;
use App\Models\SigningProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Connect;

/*
 * The CSC API v2 driver (ADR-046 S17) against a faked service: the request
 * and answer fields of info, oauth2/authorize, oauth2/token,
 * credentials/list and signatures/signHash as CSC API v2.0.0.2 names them,
 * and the address checks (public https only, no redirects). No network.
 */

const S17_BASE = 'https://underskrift.example/csc/v2';

function s17CscConfig(string $base = S17_BASE): SigningProvider
{
    $provider = new SigningProvider(['driver' => 'csc', 'name' => 'Tjänsten', 'base_url' => $base, 'client_id' => 'klient-1', 'client_secret' => 'hemlig', 'claimed_level' => 'QES']);
    $provider->id = 7;

    return $provider;
}

function s17CscRun(?SigningProvider $config = null, array $secrets = []): ProviderRun
{
    return new ProviderRun($config ?? s17CscConfig(), 'https://nonox.example/signera/leverantor/retur', 'tillstand-1', 'sv', $secrets, ProviderRun::documentCode(str_repeat('ab12', 16)));
}

/** @return array{0: string, 1: string} a self-signed certificate (base64 DER) and its DER */
function s17CscCertificate(int $type = OPENSSL_KEYTYPE_RSA): array
{
    $key = openssl_pkey_new($type === OPENSSL_KEYTYPE_RSA ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => 'Anna Andersson', 'serialNumber' => '19800101-0000'], $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
    openssl_x509_export($certificate, $pem);
    $der = (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', (string) $pem), true);

    return [base64_encode($der), $der];
}

/** @return array<string, mixed> */
function s17Info(array $overrides = []): array
{
    return [
        'specs' => '2.0.0.2',
        'name' => 'Exempeltjänsten QES',
        'logo' => 'https://underskrift.example/logo.png',
        'region' => 'SE',
        'lang' => 'sv-SE',
        'description' => 'En tjänst',
        'authType' => ['oauth2code'],
        'oauth2' => 'https://auth.underskrift.example/',
        'methods' => ['info', 'credentials/list', 'credentials/info', 'signatures/signHash'],
        ...$overrides,
    ];
}

beforeEach(function () {
    Connect::fakeDns(['underskrift.example' => ['93.184.216.34'], 'auth.underskrift.example' => ['93.184.216.35'], 'intern.example' => ['10.0.0.5'], 'mappad.example' => ['::ffff:127.0.0.1']]);
    Http::preventStrayRequests();
});

it('describes a service from its info method', function () {
    Http::fake([S17_BASE.'/info' => Http::response(s17Info())]);

    $description = app(CscProvider::class)->describe(s17CscConfig());

    expect($description->name)->toBe('Exempeltjänsten QES')
        ->and($description->region)->toBe('SE')
        ->and($description->oauthUrl)->toBe('https://auth.underskrift.example');

    Http::assertSent(fn (Request $request): bool => $request->url() === S17_BASE.'/info' && $request->method() === 'POST' && $request['lang'] === 'sv-SE');
});

it('refuses addresses that are not public https, before any request', function (string $base, string $reason) {
    Http::fake();

    expect(fn () => app(CscProvider::class)->describe(s17CscConfig($base)))
        ->toThrow(fn (ProviderFailure $failure) => expect($failure->reason)->toBe($reason));

    Http::assertNothingSent();
})->with([
    'plain http' => ['http://underskrift.example/csc/v2', ProviderFailure::UNSUPPORTED],
    'not /csc/v2' => ['https://underskrift.example/api', ProviderFailure::UNSUPPORTED],
    'private address' => ['https://intern.example/csc/v2', ProviderFailure::UNSAFE_ADDRESS],
    'IPv4-mapped loopback' => ['https://mappad.example/csc/v2', ProviderFailure::UNSAFE_ADDRESS],
    'loopback literal' => ['https://127.0.0.1/csc/v2', ProviderFailure::UNSAFE_ADDRESS],
    'link-local literal' => ['https://[fe80::1]/csc/v2', ProviderFailure::UNSAFE_ADDRESS],
    'localhost' => ['https://localhost/csc/v2', ProviderFailure::UNSAFE_ADDRESS],
    'credentials in the address' => ['https://user:pass@underskrift.example/csc/v2', ProviderFailure::UNSAFE_ADDRESS],
]);

it('never follows a redirect, and refuses a service without signHash or OAuth', function (array $answer, string $reason) {
    Http::fake([S17_BASE.'/info' => $answer['status'] ?? 200 === 302 ? Http::response('', 302, ['Location' => 'https://127.0.0.1/']) : Http::response($answer)]);

    expect(fn () => app(CscProvider::class)->describe(s17CscConfig()))
        ->toThrow(fn (ProviderFailure $failure) => expect($failure->reason)->toBe($reason));
})->with([
    'redirect' => [['status' => 302], ProviderFailure::REFUSED],
    'no signHash' => [s17Info(['methods' => ['info', 'credentials/list']]), ProviderFailure::UNSUPPORTED],
    'no OAuth' => [s17Info(['oauth2' => null]), ProviderFailure::UNSUPPORTED],
]);

it('runs the CSC v2 flow: service authorisation, credentials/list, credential authorisation of the digest, signHash', function () {
    [$certificate] = s17CscCertificate();
    $digest = hash('sha256', 'signerade attribut', true);

    Http::fake([
        S17_BASE.'/info' => Http::response(s17Info()),
        'https://auth.underskrift.example/oauth2/token' => Http::sequence()
            ->push(['access_token' => 'tjanst-token', 'token_type' => 'Bearer', 'expires_in' => 3600])
            ->push(['access_token' => 'credential-token', 'token_type' => 'Bearer', 'expires_in' => 300]),
        S17_BASE.'/credentials/list' => Http::response([
            'credentialIDs' => ['pin-only', 'GX0112348'],
            'credentialInfos' => [
                ['credentialID' => 'pin-only', 'key' => ['status' => 'enabled', 'algo' => ['1.2.840.113549.1.1.1'], 'len' => 2048], 'cert' => ['certificates' => [$certificate]], 'auth' => ['mode' => 'explicit'], 'multisign' => 1],
                ['credentialID' => 'GX0112348', 'key' => ['status' => 'enabled', 'algo' => ['1.2.840.113549.1.1.1'], 'len' => 2048], 'cert' => ['status' => 'valid', 'certificates' => [$certificate]], 'auth' => ['mode' => 'oauth2code'], 'SCAL' => '2', 'multisign' => 1],
            ],
        ]),
        S17_BASE.'/signatures/signHash' => Http::response(['signatures' => [base64_encode('rå-signatur')]]),
    ]);

    $driver = app(CscProvider::class);
    $run = s17CscRun();
    $start = $driver->start($run);

    parse_str((string) parse_url((string) $start->url, PHP_URL_QUERY), $query);

    expect($start->kind)->toBe(ProviderStep::REDIRECT)
        ->and((string) $start->url)->toStartWith('https://auth.underskrift.example/oauth2/authorize?')
        ->and($query)->toMatchArray([
            'response_type' => 'code',
            'client_id' => 'klient-1',
            'redirect_uri' => 'https://nonox.example/signera/leverantor/retur',
            'scope' => 'service',
            'code_challenge_method' => 'S256',
            'state' => 'tillstand-1',
            'lang' => 'sv-SE',
        ])
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', (string) $run->secrets['verifier'], true)), '+/', '-_'), '='));

    $verifier = (string) $run->secrets['verifier'];
    $certificateStep = $driver->collect($run, ['code' => 'kod-1', 'state' => 'tillstand-1']);

    expect($certificateStep->kind)->toBe(ProviderStep::CERTIFICATE)
        ->and($certificateStep->certificate?->algorithm)->toBe(ProviderCertificate::RSA_SHA256)
        ->and($certificateStep->certificate?->commonName)->toBe('Anna Andersson')
        ->and($run->secrets['credential_id'])->toBe('GX0112348');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.underskrift.example/oauth2/token'
        && $request['grant_type'] === 'authorization_code' && $request['code'] === 'kod-1' && $request['client_id'] === 'klient-1'
        && $request['client_secret'] === 'hemlig' && $request['redirect_uri'] === 'https://nonox.example/signera/leverantor/retur' && $request['code_verifier'] === $verifier
        && str_starts_with($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded'));
    Http::assertSent(fn (Request $request): bool => $request->url() === S17_BASE.'/credentials/list' && $request->hasHeader('Authorization', 'Bearer tjanst-token')
        && $request['credentialInfo'] === true && $request['certificates'] === 'chain' && $request['authInfo'] === true && ! isset($request['userID']));

    $run->state = 'tillstand-2';
    $authorize = $driver->authorizeDigest($run, $digest);
    parse_str((string) parse_url((string) $authorize->url, PHP_URL_QUERY), $query);

    expect($query)->toMatchArray([
        'scope' => 'credential',
        'credentialID' => 'GX0112348',
        'numSignatures' => '1',
        'hashes' => rtrim(strtr(base64_encode($digest), '+/', '-_'), '='),
        'hashAlgorithmOID' => '2.16.840.1.101.3.4.2.1',
        'state' => 'tillstand-2',
        'code_challenge_method' => 'S256',
    ])->and($query['description'])->toBe('Underskrift av ett dokument i NONOX (dokumentkod AB12 AB12)');

    $signature = $driver->collect($run, ['code' => 'kod-2', 'state' => 'tillstand-2']);

    expect($signature->kind)->toBe(ProviderStep::SIGNATURE)
        ->and($signature->signature)->toBe('rå-signatur')
        ->and($run->secrets)->toBe(['phase' => 'signed']);

    Http::assertSent(fn (Request $request): bool => $request->url() === S17_BASE.'/signatures/signHash' && $request->hasHeader('Authorization', 'Bearer credential-token')
        && $request['credentialID'] === 'GX0112348' && $request['hashes'] === [base64_encode($digest)]
        && $request['hashAlgorithmOID'] === '2.16.840.1.101.3.4.2.1' && $request['signAlgo'] === '1.2.840.113549.1.1.1' && ! isset($request['SAD']));
});

it('finds the OAuth endpoints from RFC 8414 metadata when the service names an issuer', function () {
    Http::fake([
        S17_BASE.'/info' => Http::response(s17Info(['oauth2' => null, 'oauth2Issuer' => 'https://auth.underskrift.example/qtsp'])),
        'https://auth.underskrift.example/.well-known/oauth-authorization-server/qtsp' => Http::response(['issuer' => 'https://auth.underskrift.example/qtsp', 'authorization_endpoint' => 'https://auth.underskrift.example/qtsp/authorize', 'token_endpoint' => 'https://auth.underskrift.example/qtsp/token']),
    ]);

    $start = app(CscProvider::class)->start(s17CscRun());

    expect((string) $start->url)->toStartWith('https://auth.underskrift.example/qtsp/authorize?');
});

it('refuses RFC 8414 metadata that names another issuer', function () {
    Http::fake([
        S17_BASE.'/info' => Http::response(s17Info(['oauth2' => null, 'oauth2Issuer' => 'https://auth.underskrift.example'])),
        'https://auth.underskrift.example/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://annan.example', 'authorization_endpoint' => 'https://annan.example/a', 'token_endpoint' => 'https://annan.example/t']),
    ]);

    expect(fn () => app(CscProvider::class)->start(s17CscRun()))->toThrow(fn (ProviderFailure $failure) => expect($failure->reason)->toBe(ProviderFailure::INVALID_ANSWER));
});

it('answers no credential when the signer has only PIN-protected or disabled ones', function () {
    [$certificate] = s17CscCertificate();

    Http::fake([
        'https://auth.underskrift.example/oauth2/token' => Http::response(['access_token' => 't', 'token_type' => 'Bearer']),
        S17_BASE.'/credentials/list' => Http::response(['credentialIDs' => ['a', 'b'], 'credentialInfos' => [
            ['credentialID' => 'a', 'key' => ['status' => 'disabled', 'algo' => ['1.2.840.113549.1.1.1']], 'cert' => ['certificates' => [$certificate]], 'auth' => ['mode' => 'oauth2code']],
            ['credentialID' => 'b', 'key' => ['status' => 'enabled', 'algo' => ['1.2.840.113549.1.1.1']], 'cert' => ['certificates' => [$certificate]], 'auth' => ['mode' => 'explicit']],
        ]]),
    ]);

    $run = s17CscRun(secrets: ['phase' => 'service', 'authorize' => 'https://auth.underskrift.example/oauth2/authorize', 'token' => 'https://auth.underskrift.example/oauth2/token', 'verifier' => 'v']);

    expect(fn () => app(CscProvider::class)->collect($run, ['code' => 'x']))->toThrow(fn (ProviderFailure $failure) => expect($failure->reason)->toBe(ProviderFailure::NO_CREDENTIAL));
});

it('treats access_denied as the signer cancelling, and never makes a request for it', function () {
    Http::fake();
    $run = s17CscRun(secrets: ['phase' => 'service', 'token' => 'https://auth.underskrift.example/oauth2/token', 'verifier' => 'v']);

    expect(fn () => app(CscProvider::class)->collect($run, ['error' => 'access_denied']))->toThrow(fn (ProviderFailure $failure) => expect($failure->reason)->toBe(ProviderFailure::DECLINED));

    Http::assertNothingSent();
});

it('chooses the signature algorithm from key/algo and converts a raw ECDSA value to DER', function () {
    expect(CscProvider::algorithm(['1.2.840.113549.1.1.1']))->toBe(['sign_algo' => '1.2.840.113549.1.1.1', 'pdf' => 'sha256_rsa'])
        ->and(CscProvider::algorithm(['1.2.840.113549.1.1.11']))->toBe(['sign_algo' => '1.2.840.113549.1.1.11', 'pdf' => 'sha256_rsa'])
        ->and(CscProvider::algorithm(['1.2.840.10045.4.3.2']))->toBe(['sign_algo' => '1.2.840.10045.4.3.2', 'pdf' => 'sha256_ecdsa'])
        ->and(CscProvider::algorithm(['1.2.840.113549.1.1.10']))->toBeNull();

    $raw = str_repeat("\x80", 32).str_repeat("\x01", 32);
    $der = CscProvider::ecdsaDer($raw);

    expect(bin2hex(substr($der, 0, 4)))->toBe('30450221')
        ->and(CscProvider::ecdsaDer($der))->toBe($der)
        ->and(CscProvider::metadataUrl('https://a.example/x/y/'))->toBe('https://a.example/.well-known/oauth-authorization-server/x/y');
});

it('keeps only the public facts of the signer\'s certificate, never its serialNumber attribute', function () {
    [, $der] = s17CscCertificate();
    $certificate = ProviderCertificate::fromChain([$der], ProviderCertificate::RSA_SHA256);

    expect($certificate->commonName)->toBe('Anna Andersson')
        ->and($certificate->sha256)->toBe(hash('sha256', $der))
        ->and(json_encode([$certificate->commonName, $certificate->issuerName, $certificate->serial]))->not->toContain('19800101');

    [, $ec] = s17CscCertificate(OPENSSL_KEYTYPE_EC);

    expect(fn () => ProviderCertificate::fromChain([$ec], ProviderCertificate::RSA_SHA256))->toThrow(fn (ProviderFailure $failure) => expect($failure->reason)->toBe(ProviderFailure::UNSUPPORTED_ALGORITHM));
});
