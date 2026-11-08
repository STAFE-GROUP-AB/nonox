<?php

declare(strict_types=1);

namespace App\Modules\Portal\Domain;

enum ContactStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';

    public function label(): string
    {
        return match ($this) {
            self::Invited => __('Inbjuden'),
            self::Active => __('Aktiv'),
            self::Disabled => __('Avstängd'),
        };
    }
}
