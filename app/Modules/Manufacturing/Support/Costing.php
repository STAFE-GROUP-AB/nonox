<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

use App\Modules\Inventory\Models\BomRow;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\RowStructureLine;

/**
 * The cost of one bill of materials or row copy (ADR-071 §6): per line,
 * per batch and per unit of the output, split into material, labour and
 * overhead. Incomplete when a component has no cost: the known part is
 * summed and must be shown as incomplete, never as the cost.
 */
final readonly class Costing
{
    /**
     * @param  list<array{line: BomRow|RowStructureLine, operation: ?Operation, minutes: ?int, hourly_cost_ore: ?int, material_ore: ?int, labour_ore: ?int, source: ?string, unit_cost_ore: ?int, gross_milli: ?int}>  $rows
     */
    public function __construct(
        public array $rows,
        public int $batchMaterialOre,
        public int $batchLabourOre,
        public int $batchOverheadOre,
        public int $materialPerUnitOre,
        public int $labourPerUnitOre,
        public int $overheadPerUnitOre,
        public int $overheadBp,
        public bool $complete,
    ) {}

    public function batchTotalOre(): int
    {
        return $this->batchMaterialOre + $this->batchLabourOre + $this->batchOverheadOre;
    }

    /** The cost of one unit of the output: the sum of the parts per unit. */
    public function perUnitOre(): int
    {
        return $this->materialPerUnitOre + $this->labourPerUnitOre + $this->overheadPerUnitOre;
    }

    /** Minutes of every operation in the batch. */
    public function minutes(): int
    {
        return array_sum(array_map(fn (array $row): int => (int) $row['minutes'], $this->rows));
    }
}
