<?php

declare(strict_types=1);

namespace App\Domain\Company;

enum AccountingMethod: string
{
    case Fakturametoden = 'FAKTURAMETODEN';
    case Kontantmetoden = 'KONTANTMETODEN';

    public function label(): string
    {
        return match ($this) {
            self::Fakturametoden => __('Fakturametoden'),
            self::Kontantmetoden => __('Kontantmetoden'),
        };
    }
}
