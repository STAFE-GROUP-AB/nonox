<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use Carbon\CarbonImmutable;

/** The public facts of a seal certificate, read from its X.509 certificate (never the key). */
final readonly class SealCertificateInfo
{
    public function __construct(
        public string $subject,
        public string $issuer,
        public string $serial,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
        public string $fingerprintSha256,
        public bool $selfSigned,
        public string $commonName,
    ) {}

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->notAfter->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }
}
