<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * How an article is planned (ADR-081 gap 5): not at all, by its reorder
 * point as in the purchase proposal, or by the MRP engine over a forecast
 * period. Articles that existed before the field are NONE.
 */
enum PlanningMethod: string
{
    case None = 'NONE';
    case ReorderPoint = 'REORDER_POINT';
    case Mrp = 'MRP';

    public function label(): string
    {
        return match ($this) {
            self::None => __('Ingen planering'),
            self::ReorderPoint => __('Beställningspunkt'),
            self::Mrp => __('Behovsplanering (MRP)'),
        };
    }
}
