<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Planning;

use App\Modules\Inventory\Domain\ForecastPeriodKind;
use Carbon\CarbonImmutable;

/** One forecast period: its kind, its first day and its last day (inclusive). */
final readonly class ForecastPeriod
{
    public function __construct(
        public ForecastPeriodKind $kind,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * The periods from the one holding today, $count of them (at least 1, at
     * most the kind's maximum).
     *
     * @return list<self>
     */
    public static function horizon(ForecastPeriodKind $kind, CarbonImmutable $today, int $count): array
    {
        $count = max(1, min($count, $kind->maxCount()));
        $periods = [];
        $start = $kind->startOf($today);

        for ($index = 0; $index < $count; $index++) {
            $periods[] = new self($kind, $start, $kind->endOf($start));
            $start = $kind->next($start);
        }

        return $periods;
    }
}
