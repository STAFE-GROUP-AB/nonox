<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskPriority: string
{
    case High = 'HIGH';
    case Normal = 'NORMAL';
    case Low = 'LOW';

    public function label(): string
    {
        return match ($this) {
            self::High => __('Hög'),
            self::Normal => __('Normal'),
            self::Low => __('Låg'),
        };
    }

    /** Lower sorts first. */
    public function rank(): int
    {
        return match ($this) {
            self::High => 0,
            self::Normal => 1,
            self::Low => 2,
        };
    }
}
