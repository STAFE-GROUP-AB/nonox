<?php

declare(strict_types=1);

namespace App\Domain\Company;

enum ReportingFramework: string
{
    case K1 = 'K1';
    case K2 = 'K2';
    case K3 = 'K3';

    public function label(): string
    {
        return match ($this) {
            self::K1 => __('K1 – förenklat årsbokslut'),
            self::K2 => __('K2 – mindre företag'),
            self::K3 => __('K3 – huvudregelverket'),
        };
    }
}
