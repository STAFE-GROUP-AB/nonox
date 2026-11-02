<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

use Carbon\CarbonImmutable;

/**
 * A planned or worked interval in Europe/Stockholm. A worked interval read
 * from the staff ledger may lack its check-in or its check-out.
 */
final readonly class WorkInterval
{
    public function __construct(
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public int $breakMinutes = 0,
    ) {}

    public function isComplete(): bool
    {
        return $this->start !== null && $this->end !== null && $this->end > $this->start;
    }

    /** Real minutes from start to end, whatever summer time does that night. */
    public function grossMinutes(): int
    {
        return $this->isComplete() ? intdiv((int) $this->end?->getTimestamp() - (int) $this->start?->getTimestamp(), 60) : 0;
    }

    public function workedMinutes(): int
    {
        return max(0, $this->grossMinutes() - $this->breakMinutes);
    }

    /** The instant the interval is known by: its start, else its end. */
    public function anchor(): CarbonImmutable
    {
        return $this->start ?? $this->end ?? throw new \LogicException('An interval needs a start or an end.');
    }

    /** @return array{start: ?string, end: ?string, break_minutes: int} */
    public function toArray(): array
    {
        return [
            'start' => $this->start?->toIso8601String(),
            'end' => $this->end?->toIso8601String(),
            'break_minutes' => $this->breakMinutes,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data, string $zone = 'Europe/Stockholm'): self
    {
        $start = isset($data['start']) && is_string($data['start']) ? CarbonImmutable::parse($data['start'])->setTimezone($zone) : null;
        $end = isset($data['end']) && is_string($data['end']) ? CarbonImmutable::parse($data['end'])->setTimezone($zone) : null;

        return new self($start, $end, is_numeric($data['break_minutes'] ?? null) ? (int) $data['break_minutes'] : 0);
    }
}
