<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * The engine's check of every signature and document timestamp in a file:
 * whether each is intact (the bytes it covers are unchanged) and
 * cryptographically valid, and how much of the file it covers. It says
 * nothing about whether a PDF reader trusts the certificate: no trust list
 * is consulted and nothing is fetched.
 */
final readonly class ValidationReport
{
    /**
     * @param  list<array{field: string, type: string, intact: bool, valid: bool, coverage: ?string, has_signature_timestamp: bool, certificate_sha256: ?string}>  $signatures
     */
    public function __construct(
        public string $sha256,
        public int $revisions,
        public array $signatures,
    ) {}

    /** @param  array<string, mixed>  $result  the helper's answer */
    public static function fromArray(array $result): self
    {
        $signatures = [];

        foreach ((array) ($result['signatures'] ?? []) as $signature) {
            $signature = (array) $signature;
            $signatures[] = [
                'field' => (string) ($signature['field'] ?? ''),
                'type' => (string) ($signature['type'] ?? 'signature'),
                'intact' => (bool) ($signature['intact'] ?? false),
                'valid' => (bool) ($signature['valid'] ?? false),
                'coverage' => isset($signature['coverage']) ? (string) $signature['coverage'] : null,
                'has_signature_timestamp' => (bool) ($signature['has_signature_timestamp'] ?? false),
                'certificate_sha256' => isset($signature['certificate_sha256']) ? (string) $signature['certificate_sha256'] : null,
            ];
        }

        return new self((string) ($result['sha256'] ?? ''), (int) ($result['revisions'] ?? 0), $signatures);
    }

    /** True when the file has at least one signature and every one is intact and valid. */
    public function allIntact(): bool
    {
        if ($this->signatures === []) {
            return false;
        }

        foreach ($this->signatures as $signature) {
            if (! $signature['intact'] || ! $signature['valid']) {
                return false;
            }
        }

        return true;
    }
}
