<?php

declare(strict_types=1);

namespace App\Modules\Portal\Domain;

enum ThreadKind: string
{
    case Question = 'QUESTION';
    case Dispute = 'DISPUTE';

    public function label(): string
    {
        return match ($this) {
            self::Question => __('Fråga'),
            self::Dispute => __('Bestridande'),
        };
    }
}
