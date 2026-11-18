<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Mrp;

/**
 * What a planned order would become (ADR-081 gap 5): a purchase order, or a
 * production order when the article has a bill of materials valid today and
 * Tillverkning is on.
 */
enum PlannedOrderKind: string
{
    case Purchase = 'PURCHASE';
    case Production = 'PRODUCTION';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => __('Inköp'),
            self::Production => __('Tillverkning'),
        };
    }
}
