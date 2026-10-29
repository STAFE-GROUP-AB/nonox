<?php

declare(strict_types=1);

namespace App\Domain\Company;

enum VatPeriod: string
{
    case Monthly = 'MONTHLY';
    case Quarterly = 'QUARTERLY';
    case Yearly = 'YEARLY';
    case None = 'NONE';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => __('Varje månad'),
            self::Quarterly => __('Varje kvartal'),
            self::Yearly => __('En gång per år'),
            self::None => __('Inte momsregistrerad'),
        };
    }
}
