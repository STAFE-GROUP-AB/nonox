<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shifts by the day they start, in Sweden: [first day 00:00, day after the
 * last 00:00), compared as instants so summer time cannot shift a boundary.
 */
final class ShiftQuery
{
    /** @return Builder<Shift> */
    public static function startingOn(CarbonImmutable $firstDay, CarbonImmutable $lastDay): Builder
    {
        return Shift::query()
            ->where('starts_at', '>=', self::instant($firstDay->setTimezone(LocalTimestamp::ZONE)->startOfDay()))
            ->where('starts_at', '<', self::instant($lastDay->setTimezone(LocalTimestamp::ZONE)->addDay()->startOfDay()));
    }

    public static function instant(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i:sP');
    }
}
