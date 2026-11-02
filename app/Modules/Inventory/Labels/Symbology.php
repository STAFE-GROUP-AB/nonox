<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/** The barcode types a label prints. */
enum Symbology: string
{
    case Ean13 = 'EAN13';
    case Ean8 = 'EAN8';
    case Code128 = 'CODE128';

    public function label(): string
    {
        return match ($this) {
            self::Ean13 => 'EAN-13',
            self::Ean8 => 'EAN-8',
            self::Code128 => 'Code 128',
        };
    }
}
