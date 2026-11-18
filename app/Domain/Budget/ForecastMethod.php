<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * The three deterministic ways a rolling forecast fills a month after its
 * cut-off (ADR-081 gap 2 scope 5). Each one is a rule a person can
 * recompute by hand (principle 4); none of them is a model.
 */
enum ForecastMethod: string
{
    /** The month in the fiscal year's active budget. */
    case Budget = 'BUDGET';

    /** The average of the last N closed months up to and including the cut-off. */
    case Average = 'AVERAGE';

    /** The same month last year, changed by a growth percentage. */
    case LastYear = 'LAST_YEAR';

    public function label(): string
    {
        return match ($this) {
            self::Budget => __('Budgeten'),
            self::Average => __('Snittet av senaste månaderna'),
            self::LastYear => __('Samma månad i fjol'),
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $method): string => $method->value, self::cases());
    }
}
