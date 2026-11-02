<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * A shift as its employee was last shown it, read from the published
 * snapshot, with whether it was cancelled or recently published.
 */
final readonly class PublishedShift
{
    public function __construct(
        public string $id,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public int $breakMinutes,
        public ?int $areaId,
        public ?string $note,
        public bool $cancelled,
        public bool $recent,
        public Shift $shift,
    ) {}

    public static function from(Shift $shift, CarbonImmutable $recentSince): self
    {
        $shown = $shift->published_snapshot ?? $shift->snapshot();
        $cancelled = $shift->status === Shift::CANCELLED;
        $changedAt = $cancelled ? $shift->cancelled_at : $shift->published_at;

        return new self(
            $shift->id,
            CarbonImmutable::parse((string) $shown['starts_at'])->setTimezone(LocalTimestamp::ZONE),
            CarbonImmutable::parse((string) $shown['ends_at'])->setTimezone(LocalTimestamp::ZONE),
            (int) ($shown['break_minutes'] ?? 0),
            isset($shown['area_id']) ? (int) $shown['area_id'] : null,
            isset($shown['note']) ? (string) $shown['note'] : null,
            $cancelled,
            $changedAt !== null && $changedAt->greaterThanOrEqualTo($recentSince),
            $shift,
        );
    }

    /** Published, not cancelled, and the published version is what the shift is now. */
    public function isCurrent(): bool
    {
        return $this->shift->status === Shift::PUBLISHED;
    }

    public function workedMinutes(): int
    {
        return max(0, intdiv($this->end->getTimestamp() - $this->start->getTimestamp(), 60) - $this->breakMinutes);
    }
}
