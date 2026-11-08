<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Which kind of stock an article is, and so which pair of accounts its
 * year-end value is booked on (ADR-073 §9, rule sheet RS-R1): raw materials
 * and supplies used in cooking or making (BAS 1410/4910), or goods bought
 * to be sold on unchanged (BAS 1460/4960). An article without a class has
 * the company's default class.
 */
enum StockClass: string
{
    case RawMaterial = 'RAW_MATERIAL';
    case GoodsForResale = 'GOODS_FOR_RESALE';

    public function label(): string
    {
        return match ($this) {
            self::RawMaterial => __('Råvaror och förnödenheter'),
            self::GoodsForResale => __('Handelsvaror'),
        };
    }
}
