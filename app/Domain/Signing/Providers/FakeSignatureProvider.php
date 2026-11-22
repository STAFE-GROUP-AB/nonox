<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use App\Models\SigningProvider;

/**
 * The signing provider of the test suite (ADR-046 S17): the same steps as
 * the CSC driver (identify, certificate, authorise the digest, signature)
 * without a network. It makes a throwaway RSA key and self-signed
 * certificate per run and signs the digest with it (PKCS#1 v1.5 over the
 * SHA-256 DigestInfo, as a CSC service does for rsaEncryption), so the real
 * PDF engine can check the result too. Offered only where
 * nonox.signing.providers.allow_fake is true (tests).
 */
final class FakeSignatureProvider implements SignatureProvider
{
    public const string KEY = 'fake';

    public const string AUTHORIZE_URL = 'https://underskrift.example/authorize';

    /** The DER prefix of a SHA-256 DigestInfo (RFC 8017 9.2, note 1). */
    private const string SHA256_DIGEST_INFO = "\x30\x31\x30\x0d\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01\x05\x00\x04\x20";

    /** Thrown by the next collect() (a test's failing provider). */
    public static ?ProviderFailure $failNext = null;

    /** The next signature returned is wrong (a provider that signed something else). */
    public static bool $wrongSignatureNext = false;

    /** @var list<array{step: string, provider_id: int}> */
    public static array $calls = [];

    /** Called at the start of the next collect() (a test's parallel request). */
    public static ?\Closure $beforeCollect = null;

    public static function reset(): void
    {
        self::$failNext = null;
        self::$wrongSignatureNext = false;
        self::$calls = [];
        self::$beforeCollect = null;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function claimedLevel(SigningProvider $config): SignatureLevel
    {
        return $config->claimed_level;
    }

    public function describe(SigningProvider $config): ProviderDescription
    {
        return new ProviderDescription('Testtjänst för underskrift', 'SE', null);
    }

    public function signsDocumentDigest(): bool
    {
        return true;
    }

    public function start(ProviderRun $run): ProviderStep
    {
        self::$calls[] = ['step' => 'start', 'provider_id' => $run->config->id];
        $run->secrets = ['phase' => 'service'];

        return ProviderStep::redirect(self::AUTHORIZE_URL.'?'.http_build_query(['scope' => 'service', 'state' => $run->state]));
    }

    public function collect(ProviderRun $run, array $callback): ProviderStep
    {
        self::$calls[] = ['step' => 'collect', 'provider_id' => $run->config->id];

        if (self::$beforeCollect instanceof \Closure) {
            $hook = self::$beforeCollect;
            self::$beforeCollect = null;
            $hook();
        }

        if (self::$failNext instanceof ProviderFailure) {
            $failure = self::$failNext;
            self::$failNext = null;

            throw $failure;
        }

        if (isset($callback['error'])) {
            throw new ProviderFailure(ProviderFailure::DECLINED, 'oauth_error');
        }

        if (($callback['code'] ?? '') === '') {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'no_code');
        }

        if (($run->secrets['phase'] ?? null) === 'service') {
            [$key, $certificate] = self::keyAndCertificate();
            $run->secrets = ['phase' => 'certificate', 'key' => $key];

            return ProviderStep::certificate(ProviderCertificate::fromChain([$certificate], ProviderCertificate::RSA_SHA256));
        }

        if (($run->secrets['phase'] ?? null) !== 'credential') {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER, 'phase');
        }

        $digest = (string) base64_decode((string) $run->secrets['digest'], true);

        if (self::$wrongSignatureNext) {
            self::$wrongSignatureNext = false;
            $digest = hash('sha256', 'något annat', true);
        }

        $signature = '';
        openssl_private_encrypt(self::SHA256_DIGEST_INFO.$digest, $signature, (string) $run->secrets['key'], OPENSSL_PKCS1_PADDING);
        $run->secrets = ['phase' => 'signed'];

        return ProviderStep::signature((string) $signature);
    }

    public function authorizeDigest(ProviderRun $run, string $digest): ProviderStep
    {
        self::$calls[] = ['step' => 'authorize', 'provider_id' => $run->config->id];
        $run->secrets['phase'] = 'credential';
        $run->secrets['digest'] = base64_encode($digest);

        return ProviderStep::redirect(self::AUTHORIZE_URL.'?'.http_build_query(['scope' => 'credential', 'state' => $run->state]));
    }

    /** @return array{0: string, 1: string} the PEM private key and the DER certificate */
    private static function keyAndCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($key === false) {
            throw new ProviderFailure(ProviderFailure::UNAVAILABLE);
        }

        $csr = openssl_csr_new(['commonName' => 'Test Testsson', 'organizationName' => 'Testtjänst'], $key, ['digest_alg' => 'sha256']);

        if (! $csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new ProviderFailure(ProviderFailure::UNAVAILABLE);
        }

        $certificate = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        $pem = '';
        $privatePem = '';

        if ($certificate === false || ! openssl_x509_export($certificate, $pem) || ! openssl_pkey_export($key, $privatePem)) {
            throw new ProviderFailure(ProviderFailure::UNAVAILABLE);
        }

        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '', true);

        return [$privatePem, (string) $der];
    }
}
