<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * Whether a group company is the company's customer or its supplier.
 */
enum IntercompanySide: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Customer => __('Kund'),
            self::Supplier => __('Leverantör'),
        };
    }
}
