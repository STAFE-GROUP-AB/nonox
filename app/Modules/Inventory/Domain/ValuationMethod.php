<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * How the cost of interchangeable goods on hand is calculated. Årsredovisningslagen
 * (1995:1554) 4 kap. 11 § allows first in, first out, weighted average prices or
 * a similar method, and not last in, first out.
 * Source: riksdagen.se, SFS 1995:1554, read 2026-11-02.
 *
 * K2's inventory chapter is BFNAR 2016:10 kapitel 12 Varulager (not
 * kapitel 11, which is Finansiella anläggningstillgångar; rule sheet
 * recept-och-svinn.md, read 2026-11-08).
 *
 * TODO(legal-verify): whether K2 kapitel 12 narrows the choice for K2
 * companies (for instance to FIFO only); the rule sheet quotes ÅRL 4 kap.
 * 11 § and K2 12.7 but no K2 point on the choice of method.
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
