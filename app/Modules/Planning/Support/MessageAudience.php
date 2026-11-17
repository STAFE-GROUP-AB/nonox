<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Models\Message;
use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a schedule message reaches (ADR-037 gap 5), decided once when it is
 * sent: the employees the published schedule shows in the scope (the
 * published version of each shift, so an unpublished change neither adds nor
 * removes anyone), or everyone employed in the period. One query either way.
 */
final class MessageAudience
{
    /**
     * @return list<int> employee ids, sorted
     */
    public static function employeeIds(string $scope, CarbonImmutable $from, CarbonImmutable $to, ?string $shiftId = null, ?int $areaId = null): array
    {
        if ($scope === Message::ALL) {
            return self::employed($from, $to);
        }

        $shifts = $scope === Message::SHIFT
            ? Shift::query()->whereKey((string) $shiftId)
            : self::shownIn($from, $to);

        return array_values($shifts->whereNotNull('published_at')
            ->where('status', '<>', Shift::CANCELLED)
            ->get()
            ->map(fn (Shift $shift): PublishedShift => PublishedShift::from($shift, CarbonImmutable::now()))
            ->filter(fn (PublishedShift $shown): bool => $scope === Message::SHIFT
                || ($shown->start->toDateString() >= $from->toDateString() && $shown->start->toDateString() <= $to->toDateString()
                    && ($scope !== Message::AREA || $shown->areaId === $areaId)))
            // The person the shift was shown to: an open shift that is assigned but not republished reaches nobody.
            ->map(fn (PublishedShift $shown): mixed => $shown->shift->published_snapshot === null ? $shown->shift->employee_id : ($shown->shift->published_snapshot['employee_id'] ?? null))
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->all());
    }

    /**
     * Shifts that start in the window, or whose published version does: a
     * published shift moved more than a day and not yet republished is still
     * where the employee was shown it. The snapshot's start is an ISO 8601
     * instant with its offset, compared as timestamptz.
     *
     * @return Builder<Shift>
     */
    private static function shownIn(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $start = ShiftQuery::instant($from->subDay()->setTimezone(LocalTimestamp::ZONE)->startOfDay());
        $end = ShiftQuery::instant($to->addDay()->setTimezone(LocalTimestamp::ZONE)->addDay()->startOfDay());

        return Shift::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('starts_at', '>=', $start)->where('starts_at', '<', $end))
            ->orWhereRaw("(published_snapshot->>'starts_at')::timestamptz >= ?::timestamptz AND (published_snapshot->>'starts_at')::timestamptz < ?::timestamptz", [$start, $end]));
    }

    /** @return list<int> */
    private static function employed(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(Employee::query()
            ->where('status', '<>', 'TERMINATED')
            ->whereHas('employments', fn ($query) => $query->where('start_date', '<=', $to->toDateString())
                ->where(fn ($query) => $query->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString())))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
