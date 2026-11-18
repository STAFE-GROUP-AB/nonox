<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Reports;

enum ReportVisibility: string
{
    case Private = 'PRIVATE';
    case Company = 'COMPANY';

    public function label(): string
    {
        return match ($this) {
            self::Private => __('Bara jag'),
            self::Company => __('Hela företaget'),
        };
    }
}
