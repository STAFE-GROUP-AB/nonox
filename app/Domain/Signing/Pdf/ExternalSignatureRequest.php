<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use InvalidArgumentException;

/**
 * A PAdES signature whose value is made outside NONOX (ADR-046 S17, a
 * signing provider): the signer's certificate chain (DER, end entity
 * first), the CMS signature algorithm (pyHanko's names: sha256_rsa,
 * sha256_ecdsa), the signature field and its reason. Holds no secret.
 */
final readonly class ExternalSignatureRequest
{
    public const array ALGORITHMS = ['sha256_rsa', 'sha256_ecdsa'];

    /**
     * @param  list<string>  $certificates  DER, end entity first
     */
    public function __construct(
        public array $certificates,
        public string $algorithm,
        public string $fieldName,
        public ?string $reason = null,
    ) {
        if ($certificates === [] || count($certificates) > 10) {
            throw new InvalidArgumentException('Give one to ten certificates.');
        }

        if (! in_array($algorithm, self::ALGORITHMS, true)) {
            throw new InvalidArgumentException('Unsupported signature algorithm.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,59}$/', $fieldName) !== 1) {
            throw new InvalidArgumentException('The field name must be 1 to 60 letters, digits or underscores.');
        }
    }

    /** @return array<string, mixed> the helper's parameters */
    public function toRequest(): array
    {
        return [
            'certificates' => array_map(base64_encode(...), $this->certificates),
            'signature_mechanism' => $this->algorithm,
            'field_name' => $this->fieldName,
            'reason' => $this->reason,
        ];
    }
}
