<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/** An existing order that brings an article in (ADR-081 gap 5). */
enum SupplySource: string
{
    case PurchaseOrder = 'PURCHASE_ORDER';
    case ProductionOrder = 'PRODUCTION_ORDER';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseOrder => __('Inköpsorder'),
            self::ProductionOrder => __('Tillverkningsorder'),
        };
    }
}
