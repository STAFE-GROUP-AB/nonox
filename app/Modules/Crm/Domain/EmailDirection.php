<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/** Which way a logged e-mail went (ADR-048): from the other party to us, or from us to them. */
enum EmailDirection: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function label(): string
    {
        return match ($this) {
            self::In => __('Inkommande'),
            self::Out => __('Utgående'),
        };
    }
}
