<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/** A deviation is open until a planner handles or dismisses it, or the plan shows it moved no delivery. */
enum DeviationStatus: string
{
    case Open = 'OPEN';
    case Handled = 'HANDLED';
    case Dismissed = 'DISMISSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Öppen'),
            self::Handled => __('Hanterad'),
            self::Dismissed => __('Avfärdad'),
        };
    }
}
