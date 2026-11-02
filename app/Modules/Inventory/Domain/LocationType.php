<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * The levels of the location tree. A warehouse is the root; the others
 * nest in this order, though a level may be skipped (a small shop has a
 * warehouse and shelves only).
 */
enum LocationType: string
{
    case Warehouse = 'WAREHOUSE';
    case Zone = 'ZONE';
    case Shelf = 'SHELF';
    case Bin = 'BIN';

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => __('Lager'),
            self::Zone => __('Zon'),
            self::Shelf => __('Hylla/ställ'),
            self::Bin => __('Pallplats/fack'),
        };
    }

    public function depth(): int
    {
        return match ($this) {
            self::Warehouse => 0,
            self::Zone => 1,
            self::Shelf => 2,
            self::Bin => 3,
        };
    }
}
