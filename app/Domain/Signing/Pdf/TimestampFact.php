<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * One RFC 3161 timestamp in a PDF, as the engine reads it (ADR-046 S11): a
 * seal's signature timestamp or a document timestamp, when it was made, until
 * when its TSA certificate is valid, and the hash algorithm of its imprint.
 * Long-term validation renews the file before the newest one stops being
 * reliable.
 */
final readonly class TimestampFact
{
    public const string SIGNATURE = 'signature';

    public const string DOCUMENT = 'document';

    public function __construct(
        public string $kind,
        public CarbonImmutable $at,
        public CarbonImmutable $certificateNotAfter,
        public string $mdAlgorithm,
    ) {}

    /** @param  array<mixed>  $fact  one entry of the helper's "timestamps" list */
    public static function fromArray(array $fact): ?self
    {
        $kind = $fact['kind'] ?? null;
        $algorithm = $fact['md_algorithm'] ?? null;

        if (! in_array($kind, [self::SIGNATURE, self::DOCUMENT], true) || ! is_string($algorithm) || preg_match('/^[a-z0-9_-]{1,30}$/', $algorithm) !== 1) {
            return null;
        }

        try {
            $at = CarbonImmutable::parse((string) ($fact['at'] ?? ''))->utc();
            $notAfter = CarbonImmutable::parse((string) ($fact['certificate_not_after'] ?? ''))->utc();
        } catch (Throwable) {
            return null;
        }

        return new self($kind, $at, $notAfter, $algorithm);
    }
}
