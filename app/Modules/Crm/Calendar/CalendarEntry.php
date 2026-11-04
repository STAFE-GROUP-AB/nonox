<?php

declare(strict_types=1);

namespace App\Modules\Crm\Calendar;

use Carbon\CarbonImmutable;

/**
 * One planned CRM activity as a calendar event (ADR-049): what a feed, a
 * downloaded file, an invitation and a synced calendar all receive. The
 * summary and description already follow the privacy choice they were
 * built with (CalendarEntries).
 */
final readonly class CalendarEntry
{
    public function __construct(
        public int $activityId,
        public string $uid,
        public int $sequence,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public string $summary,
        public ?string $description,
        public ?string $location,
        public ?string $url,
        public CarbonImmutable $lastModified,
    ) {}
}
