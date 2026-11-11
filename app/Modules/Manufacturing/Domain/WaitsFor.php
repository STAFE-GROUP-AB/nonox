<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/** What a production order waits for in the plan (ADR-071 §13.1): material, free capacity, or nothing. */
enum WaitsFor: string
{
    case Material = 'MATERIAL';
    case Capacity = 'CAPACITY';
    case Nothing = 'NOTHING';

    public function label(): string
    {
        return match ($this) {
            self::Material => __('Material'),
            self::Capacity => __('Kapacitet'),
            self::Nothing => __('Inget'),
        };
    }
}
