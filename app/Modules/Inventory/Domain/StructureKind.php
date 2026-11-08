<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * What a structure is (ADR-073 §2): a recipe in Kök, or a bill of
 * materials in manufacturing (ADR-071). Both use the same engine.
 */
enum StructureKind: string
{
    case Recipe = 'RECIPE';
    case Bom = 'BOM';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => __('Recept'),
            self::Bom => __('Produktstruktur'),
        };
    }
}
