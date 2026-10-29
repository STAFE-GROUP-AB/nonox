<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

enum FiscalYearStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Öppet'),
            self::Closed => __('Stängt'),
        };
    }
}
