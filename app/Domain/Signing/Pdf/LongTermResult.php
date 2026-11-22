<?php

declare(strict_types=1);

namespace App\Domain\Signing\Pdf;

/**
 * A PDF the engine wrote for long-term validation (ADR-046 S11): validation
 * data added (B-LT), or validation data and a new document timestamp (B-LTA).
 * Lists every timestamp in the written file, oldest first.
 */
final readonly class LongTermResult
{
    /**
     * @param  list<TimestampFact>  $timestamps
     */
    public function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public array $timestamps = [],
        public ?string $timestampUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $answer  the helper's result
     */
    public static function fromAnswer(string $path, array $answer): self
    {
        $timestamps = [];

        foreach ((array) ($answer['timestamps'] ?? []) as $fact) {
            $parsed = is_array($fact) ? TimestampFact::fromArray($fact) : null;

            if ($parsed instanceof TimestampFact) {
                $timestamps[] = $parsed;
            }
        }

        usort($timestamps, fn (TimestampFact $a, TimestampFact $b): int => $a->at <=> $b->at);

        return new self(
            $path,
            (int) ($answer['bytes'] ?? 0),
            (string) ($answer['sha256'] ?? ''),
            $timestamps,
            isset($answer['timestamp_url']) ? (string) $answer['timestamp_url'] : null,
        );
    }

    /** The newest timestamp in the file, the one a renewal must come before. */
    public function newest(): ?TimestampFact
    {
        return $this->timestamps === [] ? null : $this->timestamps[array_key_last($this->timestamps)];
    }
}
