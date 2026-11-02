<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

/**
 * What a label template prints: an article (with price and code) or a
 * stock location (its code to scan). Each kind has one default template.
 */
enum LabelKind: string
{
    case Article = 'ARTICLE';
    case Location = 'LOCATION';

    public function label(): string
    {
        return match ($this) {
            self::Article => __('Artikeletikett'),
            self::Location => __('Platsetikett'),
        };
    }
}
