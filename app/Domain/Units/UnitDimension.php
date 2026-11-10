<?php

declare(strict_types=1);

namespace App\Domain\Units;

/**
 * What a unit measures (ADR-071 §12.1). VOLUME and VOLUME_LIQUID share the
 * base cubic metre, so m³ and l convert without an article conversion.
 */
enum UnitDimension: string
{
    case Count = 'COUNT';
    case Length = 'LENGTH';
    case Area = 'AREA';
    case Volume = 'VOLUME';
    case VolumeLiquid = 'VOLUME_LIQUID';
    case Mass = 'MASS';
    case Time = 'TIME';
    case Other = 'OTHER';

    /** Units in the same group convert by their base factors. */
    public function group(): string
    {
        return match ($this) {
            self::VolumeLiquid => self::Volume->value,
            default => $this->value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Count => __('Antal'),
            self::Length => __('Längd'),
            self::Area => __('Yta'),
            self::Volume => __('Volym (kubik)'),
            self::VolumeLiquid => __('Volym (vätska)'),
            self::Mass => __('Vikt'),
            self::Time => __('Tid'),
            self::Other => __('Annat'),
        };
    }
}
