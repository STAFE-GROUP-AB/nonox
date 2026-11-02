<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/**
 * How the elements are placed on a label (LabelLayoutEngine): Stacked
 * centres every line, Left aligns them to the left, and Auto aligns to the
 * left and sizes the name and the price (or a location's code) to fill the
 * space they have. A QR code always sits at the right edge and a barcode at
 * the bottom.
 */
enum LabelLayout: string
{
    case Stacked = 'STACKED';
    case Left = 'LEFT';
    case Auto = 'AUTO';

    public function label(): string
    {
        return match ($this) {
            self::Stacked => __('Centrerad'),
            self::Left => __('Vänsterställd'),
            self::Auto => __('Anpassa storleken automatiskt'),
        };
    }
}
