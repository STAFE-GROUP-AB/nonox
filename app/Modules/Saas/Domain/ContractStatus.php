<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * A contract's state in one version. An ended contract is ACTIVE with a
 * cancel date: it counts until that date (SA-R15). A paused one counts 0
 * (SA-R9).
 */
enum ContractStatus: string
{
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Aktivt'),
            self::Paused => __('Pausat'),
        };
    }
}
