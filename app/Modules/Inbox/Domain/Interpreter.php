<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Domain;

/** What produced a message's proposal. */
enum Interpreter: string
{
    case Sie = 'SIE';
    case Peppol = 'PEPPOL';
    case Template = 'TEMPLATE';
    case Ai = 'AI';
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::Sie => __('SIE-läsaren'),
            self::Peppol => __('Peppol-läsaren'),
            self::Template => __('Sparad mall'),
            self::Ai => __('NONOX AI'),
            self::Manual => __('Manuellt'),
        };
    }
}
