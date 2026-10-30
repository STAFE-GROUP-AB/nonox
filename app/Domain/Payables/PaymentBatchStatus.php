<?php

declare(strict_types=1);

namespace App\Domain\Payables;

enum PaymentBatchStatus: string
{
    case Created = 'CREATED';
    case Confirmed = 'CONFIRMED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('Skapad, väntar på banken'),
            self::Confirmed => __('Betald'),
            self::Cancelled => __('Makulerad'),
        };
    }
}
