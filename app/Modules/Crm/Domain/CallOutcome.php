<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

enum CallOutcome: string
{
    case Reached = 'REACHED';
    case NoAnswer = 'NO_ANSWER';
    case LeftMessage = 'LEFT_MESSAGE';
    case WrongNumber = 'WRONG_NUMBER';

    public function label(): string
    {
        return match ($this) {
            self::Reached => __('Fick tag på'),
            self::NoAnswer => __('Inget svar'),
            self::LeftMessage => __('Lämnade meddelande'),
            self::WrongNumber => __('Fel nummer'),
        };
    }
}
