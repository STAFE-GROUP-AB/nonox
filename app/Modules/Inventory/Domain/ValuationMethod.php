<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * How the cost of interchangeable goods on hand is calculated. Årsredovisningslagen
 * (1995:1554) 4 kap. 11 § allows first in, first out, weighted average prices or
 * a similar method, and not last in, first out.
 * Source: riksdagen.se, SFS 1995:1554, read 2026-11-02.
 *
 * TODO(legal-verify): whether BFNAR 2016:10 (K2) kap. 11 narrows the choice
 * for K2 companies (for instance to FIFO only); the K2 text was not read.
 */
enum ValuationMethod: string
{
    case Fifo = 'FIFO';
    case Average = 'AVERAGE';

    public function label(): string
    {
        return match ($this) {
            self::Fifo => __('Först in, först ut (FIFO)'),
            self::Average => __('Vägt genomsnittspris'),
        };
    }
}
