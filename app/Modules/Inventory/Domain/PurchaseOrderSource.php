<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * How a purchase order came about (ADR-071 §2): written by hand, created
 * from the purchase proposal (reorder points and open customer orders, §5),
 * or, once manufacturing is built, from a production order's shortage.
 */
enum PurchaseOrderSource: string
{
    case Manual = 'MANUAL';
    case Proposal = 'PROPOSAL';
    case ProductionOrder = 'PRODUCTION_ORDER';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Manuell'),
            self::Proposal => __('Inköpsförslag'),
            self::ProductionOrder => __('Tillverkningsorder'),
        };
    }
}
