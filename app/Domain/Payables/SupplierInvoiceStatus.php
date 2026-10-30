<?php

declare(strict_types=1);

namespace App\Domain\Payables;

enum SupplierInvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Registered = 'REGISTERED';
    case Approved = 'APPROVED';
    case Paid = 'PAID';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Registered => __('Väntar på attest'),
            self::Approved => __('Attesterad, att betala'),
            self::Paid => __('Betald'),
        };
    }
}
