<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * How the customer was reached, or tried: for an oral approval and for the
 * contact attempts before work done without an answer (AO-R3, AO-R4).
 */
enum ContactChannel: string
{
    case Phone = 'PHONE';
    case Email = 'EMAIL';
    case Sms = 'SMS';
    case InPerson = 'IN_PERSON';

    public function label(): string
    {
        return match ($this) {
            self::Phone => __('Telefon'),
            self::Email => __('E-post'),
            self::Sms => __('Sms'),
            self::InPerson => __('På plats'),
        };
    }
}
