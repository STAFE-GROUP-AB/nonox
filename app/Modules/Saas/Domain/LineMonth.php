<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * One revenue line's part of a customer's MRR at a month-end: the contract
 * version it came from, its monthly amount in its own currency and in öre at
 * the month's rate. A customer's MRR is the sum of its lines' öre, so the
 * drill-down always adds up (SA-R4, SA-R21).
 */
final readonly class LineMonth
{
    public function __construct(
        public SourceType $sourceType,
        public string $sourceId,
        public int $versionId,
        public string $lineKey,
        public string $description,
        public string $currency,
        public int $monthlyMinor,
        public int $sekOre,
        public ?string $cancelEffectiveOn = null,
    ) {}

    /** The same line in öre at another rate (SEK lines are unchanged). */
    public function at(?int $micros): int
    {
        if ($this->currency === 'SEK' || $micros === null) {
            return $this->sekOre;
        }

        return Arithmetic::toOre($this->monthlyMinor, $this->currency, $micros);
    }

    /** @return array{source_type: string, source_id: string, version_id: int, line_key: string, description: string, currency: string, monthly_minor: int, sek_ore: int, cancel_effective_on: ?string} */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType->value,
            'source_id' => $this->sourceId,
            'version_id' => $this->versionId,
            'line_key' => $this->lineKey,
            'description' => $this->description,
            'currency' => $this->currency,
            'monthly_minor' => $this->monthlyMinor,
            'sek_ore' => $this->sekOre,
            'cancel_effective_on' => $this->cancelEffectiveOn,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            SourceType::from((string) $data['source_type']),
            (string) $data['source_id'],
            (int) $data['version_id'],
            (string) $data['line_key'],
            (string) $data['description'],
            (string) $data['currency'],
            (int) $data['monthly_minor'],
            (int) $data['sek_ore'],
            isset($data['cancel_effective_on']) ? (string) $data['cancel_effective_on'] : null,
        );
    }
}
