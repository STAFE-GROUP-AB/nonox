<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * The advice on a planned order (ADR-081 gap 5): it must be released within
 * a week to arrive in time, or its release date has already passed, so it
 * arrives after it is needed unless the lead time can be shortened.
 */
enum PlannedOrderException: string
{
    case ReleaseNow = 'RELEASE_NOW';
    case Late = 'LATE';

    public function label(): string
    {
        return match ($this) {
            self::ReleaseNow => __('Släpp nu'),
            self::Late => __('Försenad'),
        };
    }
}
