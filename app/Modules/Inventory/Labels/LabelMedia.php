<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/**
 * What the labels are printed on: an A4 sheet of labels (a grid with real
 * sheet margins and pitch) or a roll in a label printer (one label per page).
 */
enum LabelMedia: string
{
    case Sheet = 'SHEET';
    case Roll = 'ROLL';

    public function label(): string
    {
        return match ($this) {
            self::Sheet => __('A4-ark'),
            self::Roll => __('Etikettskrivare (rulle)'),
        };
    }
}
