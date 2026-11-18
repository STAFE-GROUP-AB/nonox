<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * The levels of the location tree. A warehouse is the root; the others
 * nest in this order, though a level may be skipped (a small shop has a
 * warehouse and shelves only). Transit is not a level: it is where goods
 * wait between two warehouses (ADR-081 E13) and comes last so the levels keep
 * their positions in cases().
 */
enum LocationType: string
{
    case Warehouse = 'WAREHOUSE';
    case Zone = 'ZONE';
    case Shelf = 'SHELF';
    case Bin = 'BIN';

    /** På väg: goods sent between two warehouses and not yet received (ADR-081 E13). Made by the system, kept inactive, never chosen by hand. */
    case Transit = 'TRANSIT';

    public function label(): string
    {
        return match ($this) {
            self::Warehouse => __('Lager'),
            self::Zone => __('Zon'),
            self::Shelf => __('Hylla/ställ'),
            self::Bin => __('Pallplats/fack'),
            self::Transit => __('På väg'),
        };
    }

    public function depth(): int
    {
        return match ($this) {
            self::Warehouse => 0,
            self::Zone => 1,
            self::Shelf => 2,
            self::Bin => 3,
            self::Transit => 0,
        };
    }
}
