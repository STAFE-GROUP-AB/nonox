<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

enum AgreementStatus: string
{
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Ended = 'ENDED';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Aktivt'),
            self::Paused => __('Pausat'),
            self::Ended => __('Avslutat'),
        };
    }
}
