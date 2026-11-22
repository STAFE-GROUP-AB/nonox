<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers\Csc;

use App\Domain\Signing\Providers\ProviderCertificate;
use App\Domain\Signing\Providers\ProviderDescription;
use App\Domain\Signing\Providers\ProviderFailure;
use App\Domain\Signing\Providers\ProviderHttp;
use App\Domain\Signing\Providers\ProviderRun;
use App\Domain\Signing\Providers\ProviderStep;
use App\Domain\Signing\Providers\SignatureLevel;
use App\Domain\Signing\Providers\SignatureProvider;
use App\Models\SigningProvider;
use Illuminate\Support\Str;

/**
 * A remote signing service with the Cloud Signature Consortium API v2
 * (ADR-046 amendment, gap 12, package S17), read in "Architectures and
 * protocols for remote signature applications", CSC API v2.0.0.2
 * (cloudsignatureconsortium.org, csc-api-v2.0.0.2.pdf, read 2026-11-22).
 *
 * The flow, with the sections of that specification relied on:
 *
 * 1. info (11.1): the service's name, region, methods and its OAuth 2.0
 *    authorization server, either "oauth2" (a base URI to which the paths
 *    oauth2/authorize and oauth2/token are concatenated, 8.4) or
 *    "oauth2Issuer" (RFC 8414 metadata).
 * 2. Service authorization with the authorization code flow (8.4.2): the
 *    signer's browser goes to oauth2/authorize with response_type=code,
 *    client_id, redirect_uri, scope=service, code_challenge (REQUIRED) with
 *    code_challenge_method=S256, state and lang; the code comes back to
 *    /signera/leverantor/retur and is exchanged at oauth2/token (8.4.4,
 *    form-encoded, grant_type=authorization_code, client_id, client_secret,
 *    redirect_uri; PKCE's code_verifier from RFC 7636). The token is
 *    user-specific, so credentials/list takes no userID (11.4, note 27).
 * 3. credentials/list (11.4) with credentialInfo, certificates=chain,
 *    certInfo, authInfo and onlyValid: the first credential whose key/status
 *    is "enabled" and whose auth/mode is "oauth2code" is used, with its
 *    cert/certificates (end entity first) and key/algo.
 * 4. NONOX prepares the signer's PAdES signature in the PDF engine and gets
 *    the digest of its signed attributes (SHA-256).
 * 5. Credential authorization (8.4.2): oauth2/authorize again with
 *    scope=credential, credentialID, numSignatures=1, hashes (the digest,
 *    base64url, which binds the authorization to it: required for SCAL2) and
 *    hashAlgorithmOID 2.16.840.1.101.3.4.2.1 (SHA-256); the code is exchanged
 *    for a token with scope "credential" (8.4.4).
 * 6. signatures/signHash (11.10) with that token in the Authorization header
 *    ("an access token with scope credential ... instead of a classical SAD",
 *    8.4): credentialID, hashes (base64), hashAlgorithmOID and signAlgo from
 *    the credential's key/algo; the answer's signatures[0] is the raw
 *    signature value, which the engine puts into the signer's CMS.
 *
 * Supported keys: RSA (signAlgo rsaEncryption 1.2.840.113549.1.1.1 with the
 * hash OID, as the specification's own example; or sha256WithRSAEncryption
 * 1.2.840.113549.1.1.11) and ECDSA with SHA-256 (1.2.840.10045.4.3.2).
 * Explicit authorization (a PIN or OTP typed into NONOX, 8.3), the
 * signDoc method, asynchronous mode and RSASSA-PSS are not supported.
 *
 * TODO(verify-csc): (1) whether base64url hashes in oauth2/authorize carry
 * padding (RFC 4648 §5 allows both; sent without). (2) The encoding of an
 * ECDSA signature value from signHash (DER is assumed; a raw r||s value of
 * even length is converted to DER). (3) Client authentication at the token
 * endpoint: the client secret is sent in the body, as the specification's
 * own example does; some services may require HTTP Basic (RFC 6749 2.3.1).
 * Each needs a real service to confirm; the test suite runs the protocol
 * against a faked service only.
 */
final class CscProvider implements SignatureProvider
{
    public const string KEY = 'csc';

    public const string SHA256_OID = '2.16.840.1.101.3.4.2.1';

    public const string RSA_OID = '1.2.840.113549.1.1.1';

    public const string RSA_SHA256_OID = '1.2.840.113549.1.1.11';

    public const string ECDSA_SHA256_OID = '1.2.840.10045.4.3.2';

    /** Credentials asked about one by one when credentials/list answers ids only. */
    private const int MAX_CREDENTIALS = 5;

    public function __construct(private readonly ProviderHttp $http) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function claimedLevel(SigningProvider $config): SignatureLevel
    {
        return $config->claimed_level;
    }

    public function signsDocumentDigest(): bool
    {
        return true;
    }

    public function describe(SigningProvider $config): ProviderDescription
    {
        $info = $this->info($config->base_url);
        $endpoints = $this->endpoints($info);

        return new ProviderDescription(
            mb_substr(self::text($info['name'] ?? null) ?? __('Tjänst för underskrift'), 0, 255),
            preg_match('/^[A-Z]{2}$/', (string) ($info['region'] ?? '')) === 1 ? (string) $info['region'] : null,
            $endpoints['source'],
        );
    }

    public function start(ProviderRun $run): ProviderStep
    {
        $endpoints = $this->endpoints($this->info($run->config->base_url));
        $verifier = self::verifier();

        $run->secrets = [
            'phase' => 'service',
            'authorize' => $endpoints['authorize'],
            'token' => $endpoints['token'],
            'verifier' => $verifier,
        ];

        return ProviderStep::redirect($this->authorizeUrl($run, $endpoints['authorize'], [
            'scope' => 'service',
        ], $verifier));
    }

    public function collect(ProviderRun $run, array $callback): ProviderStep
    {
        if (isset($callback['error'])) {
            throw new ProviderFailure($callback['error'] === 'access_denied' ? ProviderFailure::DECLINED : ProviderFailure::REFUSED, 'oauth_error');
        }

        $code = $callback['code'] ?? null;

        if (! is_string($code) || $code === '' || strlen($code) > 2000) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'no_code');
        }

        $token = $this->token($run, $code);

        return match ($run->secrets['phase'] ?? null) {
            'service' => $this->credential($run, $token),
            'credential' => $this->signHash($run, $token),
            default => throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'phase'),
        };
    }

    public function authorizeDigest(ProviderRun $run, string $digest): ProviderStep
    {
        if (($run->secrets['phase'] ?? null) !== 'certificate' || strlen($digest) !== 32) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'phase');
        }

        $verifier = self::verifier();
        $run->secrets['phase'] = 'credential';
        $run->secrets['verifier'] = $verifier;
        $run->secrets['digest'] = base64_encode($digest);

        return ProviderStep::redirect($this->authorizeUrl($run, (string) $run->secrets['authorize'], [
            'scope' => 'credential',
            'credentialID' => (string) $run->secrets['credential_id'],
            'numSignatures' => '1',
            'hashes' => rtrim(strtr(base64_encode($digest), '+/', '-_'), '='),
            'hashAlgorithmOID' => self::SHA256_OID,
            // A fixed text with the document code the signer saw in NONOX; never the title or anything personal (data minimisation).
            'description' => self::description($run),
        ], $verifier));
    }

    /** The authorisation's description (8.4.2, at most 500 characters): a fixed text and the document code. */
    public static function description(ProviderRun $run): string
    {
        $code = $run->documentCode === '' ? '' : ($run->language === 'en' ? " (document code {$run->documentCode})" : " (dokumentkod {$run->documentCode})");

        return ($run->language === 'en' ? 'Signing a document in NONOX' : 'Underskrift av ett dokument i NONOX').$code;
    }

    /** After the service authorization: the signer's first usable credential and its certificate chain. */
    private function credential(ProviderRun $run, string $token): ProviderStep
    {
        $list = $this->http->postJson($this->method($run->config->base_url, 'credentials/list'), [
            'credentialInfo' => true,
            'certificates' => 'chain',
            'certInfo' => true,
            'authInfo' => true,
            'onlyValid' => true,
            'lang' => $run->language === 'en' ? 'en-US' : 'sv-SE',
        ], $token);

        $infos = is_array($list['credentialInfos'] ?? null) ? array_values($list['credentialInfos']) : [];

        if ($infos === []) {
            foreach (array_slice(is_array($list['credentialIDs'] ?? null) ? array_values($list['credentialIDs']) : [], 0, self::MAX_CREDENTIALS) as $id) {
                if (is_string($id) && $id !== '') {
                    $infos[] = ['credentialID' => $id, ...$this->http->postJson($this->method($run->config->base_url, 'credentials/info'), [
                        'credentialID' => $id,
                        'certificates' => 'chain',
                        'certInfo' => true,
                        'authInfo' => true,
                    ], $token)];
                }
            }
        }

        foreach ($infos as $info) {
            if (! is_array($info) || ! is_string($info['credentialID'] ?? null) || ($info['key']['status'] ?? null) !== 'enabled') {
                continue;
            }

            // Only the OAuth 2.0 authorization code flow: a PIN or OTP is never typed into NONOX (8.3 is not supported).
            if (($info['auth']['mode'] ?? null) !== 'oauth2code') {
                continue;
            }

            $algorithms = array_values(array_filter((array) ($info['key']['algo'] ?? []), 'is_string'));
            $choice = self::algorithm($algorithms);

            if ($choice === null) {
                throw new ProviderFailure(ProviderFailure::UNSUPPORTED_ALGORITHM);
            }

            $chain = [];

            foreach ((array) ($info['cert']['certificates'] ?? []) as $encoded) {
                $der = is_string($encoded) ? base64_decode($encoded, true) : false;

                if ($der === false) {
                    throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'certificate');
                }

                $chain[] = $der;
            }

            $certificate = ProviderCertificate::fromChain($chain, $choice['pdf']);

            $run->secrets['phase'] = 'certificate';
            $run->secrets['credential_id'] = mb_substr($info['credentialID'], 0, 500);
            $run->secrets['sign_algo'] = $choice['sign_algo'];
            unset($run->secrets['verifier']);

            return ProviderStep::certificate($certificate);
        }

        throw new ProviderFailure(ProviderFailure::NO_CREDENTIAL);
    }

    /** After the credential authorization: the signature over the digest the signer authorised. */
    private function signHash(ProviderRun $run, string $token): ProviderStep
    {
        $algorithm = (string) ($run->secrets['sign_algo'] ?? '');
        $body = [
            'credentialID' => (string) ($run->secrets['credential_id'] ?? ''),
            'hashes' => [(string) ($run->secrets['digest'] ?? '')],
            'hashAlgorithmOID' => self::SHA256_OID,
            'signAlgo' => $algorithm,
        ];

        $answer = $this->http->postJson($this->method($run->config->base_url, 'signatures/signHash'), $body, $token);
        $signatures = $answer['signatures'] ?? null;
        $signature = is_array($signatures) && is_string($signatures[0] ?? null) ? base64_decode($signatures[0], true) : false;

        if ($signature === false || $signature === '' || strlen($signature) > 2048) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'signature');
        }

        $run->secrets = ['phase' => 'signed'];

        return ProviderStep::signature($algorithm === self::ECDSA_SHA256_OID ? self::ecdsaDer($signature) : $signature);
    }

    /** The authorization code exchanged at the token endpoint (8.4.4), with the PKCE verifier (RFC 7636 4.5). */
    private function token(ProviderRun $run, string $code): string
    {
        $answer = $this->http->postForm((string) ($run->secrets['token'] ?? ''), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $run->config->client_id,
            'client_secret' => (string) $run->config->client_secret,
            'redirect_uri' => $run->redirectUri,
            'code_verifier' => (string) ($run->secrets['verifier'] ?? ''),
        ]);

        $token = $answer['access_token'] ?? null;

        if (! is_string($token) || $token === '' || strlen($token) > 8000) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'token');
        }

        return $token;
    }

    /** @param  array<string, string>  $parameters */
    private function authorizeUrl(ProviderRun $run, string $endpoint, array $parameters, string $verifier): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $run->config->client_id,
            'redirect_uri' => $run->redirectUri,
            ...$parameters,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => $run->state,
            'lang' => $run->language === 'en' ? 'en-US' : 'sv-SE',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    /** @return array<string, mixed> */
    private function info(string $baseUrl): array
    {
        if (! self::isBaseUrl($baseUrl)) {
            throw new ProviderFailure(ProviderFailure::UNSUPPORTED, 'base_url');
        }

        $info = $this->http->postJson($this->method($baseUrl, 'info'), ['lang' => 'sv-SE']);
        $methods = array_filter((array) ($info['methods'] ?? []), 'is_string');

        if (! in_array('credentials/list', $methods, true) || ! in_array('signatures/signHash', $methods, true)) {
            throw new ProviderFailure(ProviderFailure::UNSUPPORTED, 'methods');
        }

        return $info;
    }

    /**
     * The OAuth 2.0 endpoints from info (8.4): "oauth2" is a base URI, "oauth2Issuer" an issuer whose RFC 8414
     * metadata names them. Each is checked by ProviderHttp before it is used server-side.
     *
     * @param  array<string, mixed>  $info
     * @return array{authorize: string, token: string, source: string}
     */
    private function endpoints(array $info): array
    {
        $base = self::text($info['oauth2'] ?? null);

        if ($base !== null) {
            $base = rtrim($base, '/');
            $endpoints = ['authorize' => "{$base}/oauth2/authorize", 'token' => "{$base}/oauth2/token", 'source' => $base];
        } elseif (($issuer = self::text($info['oauth2Issuer'] ?? null)) !== null) {
            $metadata = $this->http->getJson(self::metadataUrl($issuer));

            if (($metadata['issuer'] ?? null) !== $issuer || ! is_string($metadata['authorization_endpoint'] ?? null) || ! is_string($metadata['token_endpoint'] ?? null)) {
                throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'metadata');
            }

            $endpoints = ['authorize' => $metadata['authorization_endpoint'], 'token' => $metadata['token_endpoint'], 'source' => $issuer];
        } else {
            throw new ProviderFailure(ProviderFailure::UNSUPPORTED, 'oauth2');
        }

        // The browser goes to the authorization endpoint, the server to the token endpoint: both https, the latter public.
        if (! str_starts_with(strtolower($endpoints['authorize']), 'https://')) {
            throw new ProviderFailure(ProviderFailure::UNSAFE_ADDRESS, 'authorize');
        }

        $this->http->check($endpoints['token']);

        return $endpoints;
    }

    /** RFC 8414 §3: the well-known path is inserted between the host and any path of the issuer. */
    public static function metadataUrl(string $issuer): string
    {
        $parts = parse_url($issuer);

        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new ProviderFailure(ProviderFailure::UNSAFE_ADDRESS, 'issuer');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return 'https://'.$parts['host'].$port.'/.well-known/oauth-authorization-server'.$path;
    }

    /** The base URI ends with /csc/v2 (7.2), over https. */
    public static function isBaseUrl(string $url): bool
    {
        return strlen($url) <= 500
            && str_starts_with(strtolower($url), 'https://')
            && preg_match('#/csc/v2/?$#', $url) === 1
            && ! str_contains($url, '?')
            && ! str_contains($url, '#');
    }

    private function method(string $baseUrl, string $method): string
    {
        return rtrim($baseUrl, '/').'/'.$method;
    }

    /**
     * The signature algorithm for a credential's key/algo list.
     *
     * @param  list<string>  $algorithms
     * @return ?array{sign_algo: string, pdf: string}
     */
    public static function algorithm(array $algorithms): ?array
    {
        foreach ([self::RSA_OID, self::RSA_SHA256_OID] as $rsa) {
            if (in_array($rsa, $algorithms, true)) {
                return ['sign_algo' => $rsa, 'pdf' => ProviderCertificate::RSA_SHA256];
            }
        }

        if (in_array(self::ECDSA_SHA256_OID, $algorithms, true)) {
            return ['sign_algo' => self::ECDSA_SHA256_OID, 'pdf' => ProviderCertificate::ECDSA_SHA256];
        }

        return null;
    }

    /** An ECDSA value as DER (a SEQUENCE of two INTEGERs); a raw r||s value is converted. TODO(verify-csc): (2) above. */
    public static function ecdsaDer(string $signature): string
    {
        if ($signature[0] === "\x30") {
            return $signature;
        }

        if (strlen($signature) % 2 !== 0) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'ecdsa');
        }

        $half = intdiv(strlen($signature), 2);
        $integer = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");

            if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".chr(strlen($bytes)).$bytes;
        };
        $body = $integer(substr($signature, 0, $half)).$integer(substr($signature, $half));

        return "\x30".chr(strlen($body)).$body;
    }

    private static function verifier(): string
    {
        // RFC 7636 4.1: 43–128 characters of [A-Za-z0-9-._~]; 64 random ones.
        return Str::random(64);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
