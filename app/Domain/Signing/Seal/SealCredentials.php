<?php

declare(strict_types=1);

namespace App\Domain\Signing\Seal;

use SensitiveParameter;

/**
 * The key a document is sealed with: a company's own certificate (COMPANY)
 * or the instance's self-signed seal (INSTANCE). The PKCS#12 and password
 * only ever go to the PDF engine on standard input; this object never
 * prints them ({@see __debugInfo}).
 */
final readonly class SealCredentials
{
    public const string COMPANY = 'COMPANY';

    public const string INSTANCE = 'INSTANCE';

    public function __construct(
        public string $source,
        public SealCertificateInfo $info,
        #[SensitiveParameter] public string $pkcs12,
        #[SensitiveParameter] public string $password,
        public ?int $certificateId = null,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'source' => $this->source,
            'certificateId' => $this->certificateId,
            'fingerprint' => $this->info->fingerprintSha256,
            'pkcs12' => '[hidden]',
            'password' => '[hidden]',
        ];
    }
}
