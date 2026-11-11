<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Domain;

/**
 * What an operation on the shop floor is waiting for (ADR-071 §7, §13.5):
 * material that has not arrived, or work, meaning the material is there
 * but the step before it, a machine or someone to do it is not. The
 * analysis keeps the two apart, since they have different remedies:
 * buying earlier, or planning capacity.
 */
enum WaitingKind: string
{
    case Material = 'MATERIAL';
    case Work = 'WORK';

    public function label(): string
    {
        return match ($this) {
            self::Material => __('Väntar på material'),
            self::Work => __('Väntar på arbete'),
        };
    }
}
