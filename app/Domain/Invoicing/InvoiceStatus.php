<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

enum InvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Issued = 'ISSUED';
    case Paid = 'PAID';
    case Credited = 'CREDITED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Issued => __('Obetald'),
            self::Paid => __('Betald'),
            self::Credited => __('Krediterad'),
        };
    }
}
