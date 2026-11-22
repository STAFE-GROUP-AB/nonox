<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use InvalidArgumentException;

/**
 * The next step of a provider run (ADR-046 S17): send the browser to the
 * provider (REDIRECT), the signer's certificate is known and the digest can
 * be prepared (CERTIFICATE), or the provider's signature over the digest is
 * here (SIGNATURE). COMPLETED is for a provider that does not sign the PDF's
 * digest (BankID, S18): its evidence is kept instead. Never dumped with the
 * signature bytes.
 */
final readonly class ProviderStep
{
    public const string REDIRECT = 'REDIRECT';

    public const string CERTIFICATE = 'CERTIFICATE';

    public const string SIGNATURE = 'SIGNATURE';

    public const string COMPLETED = 'COMPLETED';

    /**
     * @param  array<string, scalar|null>  $evidence
     */
    private function __construct(
        public string $kind,
        public ?string $url = null,
        public ?ProviderCertificate $certificate = null,
        public ?string $signature = null,
        public array $evidence = [],
    ) {}

    public static function redirect(string $url): self
    {
        if (! str_starts_with(strtolower($url), 'https://')) {
            throw new InvalidArgumentException('A provider step must send the browser to an https address.');
        }

        return new self(self::REDIRECT, url: $url);
    }

    public static function certificate(ProviderCertificate $certificate): self
    {
        return new self(self::CERTIFICATE, certificate: $certificate);
    }

    /** @param  string  $signature  the raw signature value over the digest (PKCS#1 v1.5 for RSA, DER for ECDSA) */
    public static function signature(string $signature): self
    {
        if ($signature === '') {
            throw new InvalidArgumentException('The signature is empty.');
        }

        return new self(self::SIGNATURE, signature: $signature);
    }

    /** @param  array<string, scalar|null>  $evidence */
    public static function completed(array $evidence): self
    {
        return new self(self::COMPLETED, evidence: $evidence);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'signature' => $this->signature === null ? null : '[hidden]'];
    }
}
