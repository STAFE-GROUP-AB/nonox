<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * A PDF prepared for an external signature (ADR-046 S17): the file with an
 * empty signature placeholder (its path, size and SHA-256), and what is
 * needed to finish it later, after the signer has authorised the digest at
 * the provider: the digest over the file's byte range, where the
 * placeholder is, the signed attributes (DER) and the digest of those
 * attributes, which is what the provider signs. pyHanko's interrupted
 * signing (PdfSigner.async_digest_doc_for_signing, PreparedByteRangeDigest,
 * PdfTBSDocument.async_finish_signing). Holds no secret; NONOX stores it
 * encrypted with the run so it cannot be altered between the two calls.
 */
final readonly class PreparedSignature
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public string $digest,
        public string $documentDigest,
        public int $reservedStart,
        public int $reservedEnd,
        public string $signedAttributes,
        public string $algorithm,
        public string $certificateSha256,
    ) {}

    /** @return array<string, int|string> for storage with the run; binary values as base64 */
    public function toArray(): array
    {
        return [
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'digest' => base64_encode($this->digest),
            'document_digest' => bin2hex($this->documentDigest),
            'reserved_start' => $this->reservedStart,
            'reserved_end' => $this->reservedEnd,
            'signed_attributes' => base64_encode($this->signedAttributes),
            'algorithm' => $this->algorithm,
            'certificate_sha256' => $this->certificateSha256,
        ];
    }

    /** @param  array<string, mixed>  $stored */
    public static function fromArray(string $path, array $stored): self
    {
        return new self(
            $path,
            (int) ($stored['bytes'] ?? 0),
            (string) ($stored['sha256'] ?? ''),
            (string) base64_decode((string) ($stored['digest'] ?? ''), true),
            (string) hex2bin((string) ($stored['document_digest'] ?? '')),
            (int) ($stored['reserved_start'] ?? 0),
            (int) ($stored['reserved_end'] ?? 0),
            (string) base64_decode((string) ($stored['signed_attributes'] ?? ''), true),
            (string) ($stored['algorithm'] ?? ''),
            (string) ($stored['certificate_sha256'] ?? ''),
        );
    }

    /** @return array<string, mixed> the helper's parameters for finishing */
    public function toRequest(): array
    {
        return [
            'document_digest' => bin2hex($this->documentDigest),
            'reserved_start' => $this->reservedStart,
            'reserved_end' => $this->reservedEnd,
            'signed_attributes' => base64_encode($this->signedAttributes),
            'signature_mechanism' => $this->algorithm,
        ];
    }
}
