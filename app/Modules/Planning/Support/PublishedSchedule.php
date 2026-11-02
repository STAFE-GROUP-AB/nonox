<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * An employee's schedule as they have been shown it: the published version
 * of every shift (published_snapshot), so a planner's unpublished change is
 * not seen until it is published, and a shift moved to someone else stays
 * with the employee until then. Cancelled shifts are included, marked, so
 * Min sida can show what was called off. One query.
 */
final class PublishedSchedule
{
    /** How long a publication or cancellation is marked as news. */
    public const int RECENT_DAYS = 7;

    /**
     * @return Collection<int, PublishedShift> sorted by start
     */
    public static function forEmployee(int $employeeId, CarbonImmutable $from, CarbonImmutable $to, bool $withCancelled = true): Collection
    {
        // The snapshot can lie up to a day from the current times; filter on it in memory.
        $shifts = ShiftQuery::startingOn($from->subDays(2), $to->addDays(2))
            ->whereNotNull('published_at')
            ->where('published_snapshot->employee_id', (string) $employeeId)
            ->when(! $withCancelled, fn ($query) => $query->where('status', '<>', Shift::CANCELLED))
            ->get();

        $recent = CarbonImmutable::now()->subDays(self::RECENT_DAYS);

        return $shifts
            ->map(fn (Shift $shift): PublishedShift => PublishedShift::from($shift, $recent))
            ->filter(fn (PublishedShift $shift): bool => $shift->start->greaterThanOrEqualTo($from->startOfDay()) && $shift->start->lessThan($to->addDay()->startOfDay()))
            ->sortBy(fn (PublishedShift $shift): int => $shift->start->getTimestamp())
            ->values();
    }
}
