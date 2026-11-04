<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/** What kind of agreement an entry in the agreement register is (ADR-046). */
enum AgreementKind: string
{
    case Service = 'SERVICE';
    case Support = 'SUPPORT';
    case Subscription = 'SUBSCRIPTION';
    case Framework = 'FRAMEWORK';
    case Rental = 'RENTAL';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Service => __('Tjänsteavtal'),
            self::Support => __('Support- och serviceavtal'),
            self::Subscription => __('Abonnemang'),
            self::Framework => __('Ramavtal'),
            self::Rental => __('Hyresavtal'),
            self::Other => __('Övrigt avtal'),
        };
    }
}
