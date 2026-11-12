<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/**
 * The traffic light of a forecast against the promised date (ADR-071
 * §13.3 step 6): green at least one working day early, yellow on the day
 * or when it rests on an assumed material date, red when late or past the
 * horizon, grey without a promised date.
 */
enum ForecastStatus: string
{
    case Green = 'GREEN';
    case Yellow = 'YELLOW';
    case Red = 'RED';
    case Grey = 'GREY';

    public function label(): string
    {
        return match ($this) {
            self::Green => __('I tid'),
            self::Yellow => __('På gränsen'),
            self::Red => __('Försenad'),
            self::Grey => __('Inget lovat datum'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Green => 'success',
            self::Yellow => 'warning',
            self::Red => 'danger',
            self::Grey => 'neutral',
        };
    }

    /** Worst first, for sorting a list by urgency. */
    public function rank(): int
    {
        return match ($this) {
            self::Red => 0,
            self::Yellow => 1,
            self::Green => 2,
            self::Grey => 3,
        };
    }
}
