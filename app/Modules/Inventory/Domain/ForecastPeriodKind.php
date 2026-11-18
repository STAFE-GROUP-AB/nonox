<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use Carbon\CarbonImmutable;

/**
 * The length of a forecast period (ADR-081 gap 5): a week from Monday, or a
 * calendar month. The horizon is at most 52 weeks or 12 months, and 13 weeks
 * or 3 months by default (owner decision 13).
 */
enum ForecastPeriodKind: string
{
    case Week = 'WEEK';
    case Month = 'MONTH';

    public function label(): string
    {
        return match ($this) {
            self::Week => __('Vecka'),
            self::Month => __('Månad'),
        };
    }

    public function maxCount(): int
    {
        return match ($this) {
            self::Week => 52,
            self::Month => 12,
        };
    }

    public function defaultCount(): int
    {
        return match ($this) {
            self::Week => 13,
            self::Month => 3,
        };
    }

    /** The first day of the period the date falls in. */
    public function startOf(CarbonImmutable $date): CarbonImmutable
    {
        return match ($this) {
            self::Week => $date->startOfWeek(CarbonImmutable::MONDAY)->startOfDay(),
            self::Month => $date->startOfMonth()->startOfDay(),
        };
    }

    public function isStart(CarbonImmutable $date): bool
    {
        return $this->startOf($date)->equalTo($date->startOfDay());
    }

    /** The first day of the period after the one starting on $start. */
    public function next(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Week => $start->addWeek(),
            self::Month => $start->addMonthNoOverflow()->startOfMonth(),
        };
    }

    /** The last day of the period starting on $start. */
    public function endOf(CarbonImmutable $start): CarbonImmutable
    {
        return $this->next($start)->subDay();
    }

    /** The start of the last period a forecast may be entered for, counted from today. */
    public function lastStart(CarbonImmutable $today): CarbonImmutable
    {
        $start = $this->startOf($today);

        for ($index = 1; $index < $this->maxCount(); $index++) {
            $start = $this->next($start);
        }

        return $start;
    }
}
