<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Structures;

use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;

/**
 * The cost of one structure version (RS-C3 – RS-C5): per row, per batch and
 * per yield unit (a portion, a kg). Incomplete when a component lacks a
 * cost; then perUnitOre covers only the rows with a cost and must be shown
 * as incomplete, never as the cost.
 */
final readonly class StructureCost
{
    /** The cost came from the component's own structure (a sub-recipe). */
    public const string SOURCE = 'structure';

    /**
     * @param  list<array{row: BomRow, gross_milli: int, unit_cost_ore: ?int, source: ?string, line_cost_ore: ?int, latest: ?array{ore: int, date: string}}>  $rows
     */
    public function __construct(
        public BillOfMaterials $structure,
        public array $rows,
        public int $batchCostOre,
        public int $perUnitOre,
        public bool $complete,
    ) {}
}
