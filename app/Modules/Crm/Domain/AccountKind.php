<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

enum AccountKind: string
{
    case Company = 'COMPANY';
    case Person = 'PERSON';

    public function label(): string
    {
        return match ($this) {
            self::Company => __('Företag'),
            self::Person => __('Privatperson'),
        };
    }
}
