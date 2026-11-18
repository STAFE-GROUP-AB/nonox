<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * How an article is followed through the warehouse (ADR-081 E12): not at
 * all, by batch (a code shared by many units, with an optional best-before
 * date) or by serial number (one unit per number).
 */
enum Tracking: string
{
    case None = 'NONE';
    case Batch = 'BATCH';
    case Serial = 'SERIAL';

    public function label(): string
    {
        return match ($this) {
            self::None => __('Ingen spårning'),
            self::Batch => __('Batch'),
            self::Serial => __('Serienummer'),
        };
    }

    public function isTracked(): bool
    {
        return $this !== self::None;
    }
}
