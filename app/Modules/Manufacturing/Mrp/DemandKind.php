<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * Where a requirement comes from, as the pegging of a planned order names it
 * (ADR-081 gap 5): an open customer order, the forecast above what the
 * orders already take in that week, a component of an open production
 * order, a component of a planned order of a parent article, or the safety
 * stock.
 */
enum DemandKind: string
{
    case CustomerOrder = 'CUSTOMER_ORDER';
    case Forecast = 'FORECAST';
    case ProductionOrder = 'PRODUCTION_ORDER';
    case PlannedParent = 'PLANNED_PARENT';
    case SafetyStock = 'SAFETY_STOCK';

    public function label(): string
    {
        return match ($this) {
            self::CustomerOrder => __('Kundorder'),
            self::Forecast => __('Prognos'),
            self::ProductionOrder => __('Tillverkningsorder'),
            self::PlannedParent => __('Planerad tillverkning'),
            self::SafetyStock => __('Säkerhetslager'),
        };
    }
}
