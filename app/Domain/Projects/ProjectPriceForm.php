<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * How a project is priced towards its customer (ADR-081 gap 6): the price
 * forms of work orders (ADR-074), and internal projects without a customer
 * price.
 */
enum ProjectPriceForm: string
{
    case Fixed = 'FIXED';
    case TimeAndMaterial = 'TIME_AND_MATERIAL';
    case Internal = 'INTERNAL';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => __('Fast pris'),
            self::TimeAndMaterial => __('Löpande räkning'),
            self::Internal => __('Internt projekt'),
        };
    }
}
