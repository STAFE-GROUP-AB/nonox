<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

use App\Models\Company;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureKind;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Modules\Inventory\Structures\StructureCost;
use App\Modules\Inventory\Structures\UnitCosts;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\Manufacturing\Models\Operation;
use App\Modules\Manufacturing\Models\RowStructureLine;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every bill of materials of a company with its operations and the cost of
 * every article on a day, loaded once in a fixed number of queries, and
 * the costing of ADR-071 §6 done in memory:
 *
 * - material: each component's gross quantity (loss included, RS-C2) at its
 *   cost per stock unit: the valuation cost under the company's method
 *   (FIFO or weighted average) when it is on hand, else its own bill of
 *   materials valid on the day (a sub-assembly, at its material and labour
 *   per unit), else the latest receipt cost, else the purchase price;
 * - labour: each operation's minutes at the operation's hourly cost;
 * - overhead: the company's percentage of material and labour, off by
 *   default;
 * - per unit: each part divided by the batch, rounded half up to öre, and
 *   the total as the sum of the parts, so the figures shown add up.
 *
 * Each line is rounded half up once. A management figure for quotes and
 * orders, not the stock valuation: it writes nothing.
 */
final class ManufacturingBook
{
    public const int MAX_DEPTH = 12;

    /** @var array<int, array{material: ?int, labour: ?int, source: ?string}> per article, memoised */
    private array $unitCosts = [];

    /**
     * @param  array<int, list<BillOfMaterials>>  $byOutput  output article => versions, newest first
     * @param  Collection<int, Operation>  $operations  by id
     */
    private function __construct(
        public readonly CarbonImmutable $date,
        private readonly array $byOutput,
        public readonly Collection $operations,
        public readonly UnitCosts $costs,
        public readonly int $overheadBp,
    ) {}

    public static function for(Company $company, ?CarbonImmutable $date = null): self
    {
        $date ??= CarbonImmutable::today();

        return CurrentCompany::run($company, function () use ($company, $date): self {
            $byOutput = [];

            foreach (BillOfMaterials::query()->where('kind', StructureKind::Bom)->with('rows')->orderByDesc('valid_from')->orderByDesc('version')->get() as $structure) {
                $byOutput[$structure->output_article_id][] = $structure;
            }

            return new self(
                $date,
                $byOutput,
                Operation::query()->orderBy('position')->orderBy('name')->get()->keyBy('id'),
                UnitCosts::at($company, $date),
                ManufacturingSettings::overheadBp($company),
            );
        });
    }

    /** @return list<int> every article with a bill of materials */
    public function outputs(): array
    {
        return array_keys($this->byOutput);
    }

    /** @return Collection<int, BillOfMaterials> newest first */
    public function versions(int $articleId): Collection
    {
        return collect($this->byOutput[$articleId] ?? []);
    }

    /** The active version with the latest valid_from on or before the day (the higher version wins a tie). */
    public function validOn(int $articleId, ?CarbonImmutable $date = null): ?BillOfMaterials
    {
        $date ??= $this->date;

        return $this->versions($articleId)->first(fn (BillOfMaterials $version): bool => $version->is_active && $version->valid_from->lessThanOrEqualTo($date));
    }

    /**
     * Costs a set of structure lines (a version's rows or a row copy's
     * lines) for a batch of $yieldMilli thousandths of the output.
     *
     * @param  iterable<BomRow|RowStructureLine>  $lines
     */
    public function cost(iterable $lines, int $yieldMilli): Costing
    {
        return $this->costAt($lines, $yieldMilli, 0, true);
    }

    /**
     * @param  iterable<BomRow|RowStructureLine>  $lines
     */
    private function costAt(iterable $lines, int $yieldMilli, int $depth, bool $withOverhead): Costing
    {
        // Saving refuses nesting past the limit (StructureWriter, SaveRowStructure); a structure that gets
        // there anyway is costed as incomplete rather than failing the page.
        if ($depth > self::MAX_DEPTH) {
            return new Costing([], 0, 0, 0, 0, 0, 0, 0, false);
        }

        $rows = [];
        $material = 0;
        $labour = 0;
        $complete = true;

        foreach ($lines as $line) {
            if ($line->operation_id !== null) {
                $operation = $this->operations->get($line->operation_id);
                $hourly = $operation?->hourly_cost_ore;
                $cost = $hourly === null ? null : Operation::labourOre((int) $line->minutes, $hourly);
                $complete = $complete && $cost !== null;
                $labour += $cost ?? 0;
                $rows[] = ['line' => $line, 'operation' => $operation, 'minutes' => (int) $line->minutes, 'hourly_cost_ore' => $hourly, 'material_ore' => 0, 'labour_ore' => $cost, 'source' => null, 'unit_cost_ore' => null, 'gross_milli' => null];

                continue;
            }

            $componentId = (int) $line->component_article_id;
            $gross = $line->grossMilli();
            $unit = $this->unitCostAt($componentId, $depth + 1);
            $lineMaterial = $unit['material'] === null ? null : Quantity::value($gross, $unit['material']);
            $lineLabour = $unit['labour'] === null ? null : Quantity::value($gross, $unit['labour']);

            if ($lineMaterial === null) {
                $complete = false;
            }

            $material += $lineMaterial ?? 0;
            $labour += $lineLabour ?? 0;
            $rows[] = [
                'line' => $line,
                'operation' => null,
                'minutes' => null,
                'hourly_cost_ore' => null,
                'material_ore' => $lineMaterial,
                'labour_ore' => $lineLabour,
                'source' => $unit['source'],
                'unit_cost_ore' => $unit['material'] === null ? null : $unit['material'] + ($unit['labour'] ?? 0),
                'gross_milli' => $gross,
            ];
        }

        $overhead = $withOverhead && $this->overheadBp > 0
            ? BigDecimal::of($material + $labour)->multipliedBy($this->overheadBp)->dividedBy(StructureMath::BP, 0, RoundingMode::HalfUp)->toInt()
            : 0;

        return new Costing(
            rows: $rows,
            batchMaterialOre: $material,
            batchLabourOre: $labour,
            batchOverheadOre: $overhead,
            materialPerUnitOre: StructureMath::perUnit($material, $yieldMilli),
            labourPerUnitOre: StructureMath::perUnit($labour, $yieldMilli),
            overheadPerUnitOre: StructureMath::perUnit($overhead, $yieldMilli),
            overheadBp: $withOverhead ? $this->overheadBp : 0,
            complete: $complete,
        );
    }

    /**
     * The cost per stock unit of a component, split into material and
     * labour (labour only for a sub-assembly costed from its own bill of
     * materials).
     *
     * @return array{material: ?int, labour: ?int, source: ?string}
     */
    private function unitCostAt(int $articleId, int $depth): array
    {
        if (isset($this->unitCosts[$articleId])) {
            return $this->unitCosts[$articleId];
        }

        $stock = $this->costs->of($articleId);

        if ($stock['source'] === UnitCosts::VALUATION) {
            return $this->unitCosts[$articleId] = ['material' => $stock['ore'], 'labour' => null, 'source' => UnitCosts::VALUATION];
        }

        $structure = $this->validOn($articleId);

        if ($structure !== null) {
            $sub = $this->costAt($structure->rows, $structure->yield_milli, $depth, false);

            if ($sub->complete) {
                return $this->unitCosts[$articleId] = ['material' => $sub->materialPerUnitOre, 'labour' => $sub->labourPerUnitOre, 'source' => StructureCost::SOURCE];
            }

            // An incomplete sub-assembly is costed as a bought one: its latest receipt cost, else its purchase price (design choice 4).
        }

        return $this->unitCosts[$articleId] = ['material' => $stock['ore'], 'labour' => null, 'source' => $stock['source']];
    }
}
