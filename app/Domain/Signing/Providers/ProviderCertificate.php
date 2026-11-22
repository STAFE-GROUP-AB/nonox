<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use Carbon\CarbonImmutable;

/**
 * The signer's certificate at a provider (ADR-046 S17), with the chain the
 * provider returned (end entity first), and the signature algorithm NONOX
 * will ask for. Only public facts are kept after the run: the common name,
 * the issuer's common name, the serial, the fingerprint and the expiry. The
 * full subject (which may hold a personal identity number) stays in the
 * signed PDF only.
 */
final readonly class ProviderCertificate
{
    public const int MAX_CERTIFICATES = 10;

    public const int MAX_CERTIFICATE_BYTES = 16 * 1024;

    /** pyHanko's names for the CMS signature algorithm (asn1crypto). */
    public const string RSA_SHA256 = 'sha256_rsa';

    public const string ECDSA_SHA256 = 'sha256_ecdsa';

    /**
     * @param  list<string>  $chain  DER certificates, end entity first
     */
    private function __construct(
        public array $chain,
        public string $algorithm,
        public string $commonName,
        public string $issuerName,
        public string $serial,
        public string $sha256,
        public CarbonImmutable $notAfter,
    ) {}

    /**
     * @param  list<string>  $chain  DER certificates, end entity first
     *
     * @throws ProviderFailure when the chain is empty, too large, unreadable, not valid now, or its key does not fit the algorithm
     */
    public static function fromChain(array $chain, string $algorithm): self
    {
        if ($chain === [] || count($chain) > self::MAX_CERTIFICATES || ! in_array($algorithm, [self::RSA_SHA256, self::ECDSA_SHA256], true)) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER);
        }

        foreach ($chain as $der) {
            if ($der === '' || strlen($der) > self::MAX_CERTIFICATE_BYTES) {
                throw new ProviderFailure(ProviderFailure::INVALID_ANSWER);
            }
        }

        $pem = self::pem($chain[0]);
        $parsed = @openssl_x509_parse($pem);
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (! is_array($parsed) || ! is_array($details)) {
            throw new ProviderFailure(ProviderFailure::INVALID_ANSWER);
        }

        $expected = $algorithm === self::RSA_SHA256 ? OPENSSL_KEYTYPE_RSA : OPENSSL_KEYTYPE_EC;

        if (($details['type'] ?? null) !== $expected) {
            throw new ProviderFailure(ProviderFailure::UNSUPPORTED_ALGORITHM);
        }

        $now = time();

        if ((int) ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now || (int) ($parsed['validTo_time_t'] ?? 0) <= $now) {
            throw new ProviderFailure(ProviderFailure::CERTIFICATE_NOT_VALID);
        }

        return new self(
            $chain,
            $algorithm,
            self::name($parsed['subject'] ?? []),
            self::name($parsed['issuer'] ?? []),
            mb_substr(strtoupper((string) ($parsed['serialNumberHex'] ?? '')), 0, 100),
            hash('sha256', $chain[0]),
            CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']),
        );
    }

    public static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /** The common name, else the organisation, of a parsed name; never the serialNumber attribute (often a personal identity number). */
    private static function name(mixed $name): string
    {
        $name = is_array($name) ? $name : [];
        $value = $name['CN'] ?? $name['O'] ?? '';
        $value = is_array($value) ? (string) reset($value) : (string) $value;

        return mb_substr(trim(preg_replace('/\p{C}+/u', ' ', $value) ?? ''), 0, 255);
    }
}
