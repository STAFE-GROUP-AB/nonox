<?php

declare(strict_types=1);

namespace App\Domain\Budget;

/**
 * A draft can be changed; a locked version is read-only and can only be copied.
 */
enum BudgetStatus: string
{
    case Draft = 'DRAFT';
    case Locked = 'LOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Locked => __('Låst'),
        };
    }
}
