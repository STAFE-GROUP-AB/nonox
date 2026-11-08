<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Structures;

use App\Models\Company;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Inventory\Models\BillOfMaterials;
use App\Modules\Inventory\Models\BomRow;
use App\Support\Tenancy\CurrentCompany;
use Brick\Math\BigRational;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Every structure version of a company, loaded once (two queries), with
 * the engine's work done in memory (ADR-073 §2):
 *
 * - the version of an output article valid on a date: the active version
 *   with the latest valid_from on or before it (the higher version wins a
 *   tie);
 * - exploding outputs into the quantities of the components that are not
 *   made from a structure themselves, recursively through sub-structures,
 *   each level at the version valid on the date, exact until the caller
 *   rounds (RS-C7);
 * - costing a version per row, batch and unit (RS-C3 – RS-C5), a
 *   sub-structure at its own cost per unit.
 *
 * Loops are refused when a version is saved (StructureWriter); this class
 * stops at a depth limit as a second guard.
 */
final class StructureBook
{
    public const int MAX_DEPTH = 12;

    /** @param  array<int, list<BillOfMaterials>>  $byOutput  output article => versions, newest first */
    private function __construct(private readonly array $byOutput) {}

    public static function for(Company $company): self
    {
        return CurrentCompany::run($company, function (): self {
            $structures = BillOfMaterials::query()
                ->with(['rows' => fn ($query) => $query->whereNotNull('component_article_id')])
                ->orderByDesc('valid_from')->orderByDesc('version')
                ->get();

            $byOutput = [];

            foreach ($structures as $structure) {
                $byOutput[$structure->output_article_id][] = $structure;
            }

            return new self($byOutput);
        });
    }

    /** @return Collection<int, BillOfMaterials> newest first */
    public function versions(int $outputArticleId): Collection
    {
        return collect($this->byOutput[$outputArticleId] ?? []);
    }

    /** @return list<int> every output article with a structure */
    public function outputs(): array
    {
        return array_keys($this->byOutput);
    }

    public function validOn(int $outputArticleId, CarbonImmutable $date): ?BillOfMaterials
    {
        return $this->versions($outputArticleId)
            ->first(fn (BillOfMaterials $version): bool => $version->is_active && $version->valid_from->lessThanOrEqualTo($date));
    }

    /** Whether the article is made from any active version, on any date. */
    public function isMade(int $articleId): bool
    {
        return $this->versions($articleId)->contains(fn (BillOfMaterials $version): bool => $version->is_active);
    }

    /**
     * Whether $target is reachable from $articleId through the components of
     * any active version (a loop if $target is the structure being saved).
     *
     * @param  array<int, true>  $seen
     */
    public function reaches(int $articleId, int $target, array $seen = []): bool
    {
        if ($articleId === $target) {
            return true;
        }

        if (isset($seen[$articleId])) {
            return false;
        }

        $seen[$articleId] = true;

        foreach ($this->versions($articleId) as $version) {
            if (! $version->is_active) {
                continue;
            }

            foreach ($version->rows as $row) {
                if ($row->component_article_id !== null && $this->reaches($row->component_article_id, $target, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Explodes quantities of output articles (thousandths of the yield unit:
     * portions for a dish) into the components that are not made from a
     * structure on that date. An output without a valid structure is
     * returned in 'missing' and not guessed.
     *
     * @param  array<int, int|BigRational>  $outputs  output article => quantity in thousandths
     * @return array{components: array<int, BigRational>, missing: array<int, BigRational>}
     */
    public function explode(array $outputs, CarbonImmutable $date): array
    {
        $components = [];
        $missing = [];

        foreach ($outputs as $articleId => $quantity) {
            $quantity = $quantity instanceof BigRational ? $quantity : BigRational::of($quantity);
            $structure = $this->validOn((int) $articleId, $date);

            if ($structure === null) {
                $missing[$articleId] = ($missing[$articleId] ?? BigRational::zero())->plus($quantity);

                continue;
            }

            $this->explodeInto($structure, $quantity, $date, $components, 0);
        }

        return ['components' => $components, 'missing' => $missing];
    }

    /**
     * Explodes and rounds once per component, half up (RS-C7).
     *
     * @param  array<int, int|BigRational>  $outputs
     * @return array{components: array<int, int>, missing: array<int, int>}
     */
    public function explodeRounded(array $outputs, CarbonImmutable $date): array
    {
        $exploded = $this->explode($outputs, $date);

        return [
            'components' => array_map(StructureMath::round(...), $exploded['components']),
            'missing' => array_map(StructureMath::round(...), $exploded['missing']),
        ];
    }

    /**
     * The cost of one version: per row the gross quantity, the cost per
     * unit and its source, and the line cost; the batch cost; and the cost
     * per yield unit. Incomplete when a component has no cost: the known
     * part is summed and the result says so.
     */
    public function cost(BillOfMaterials $structure, UnitCosts $costs, CarbonImmutable $date): StructureCost
    {
        return $this->costAt($structure, $costs, $date, 0);
    }

    /**
     * The cost per unit of an article: its structure's cost per yield unit
     * when it is made from one on the date (RS-C5), otherwise its cost from
     * stock.
     *
     * @return array{ore: ?int, source: ?string}
     */
    public function unitCost(int $articleId, UnitCosts $costs, CarbonImmutable $date): array
    {
        return $this->unitCostAt($articleId, $costs, $date, 0);
    }

    /** @param  array<int, BigRational>  $components */
    private function explodeInto(BillOfMaterials $structure, BigRational $quantity, CarbonImmutable $date, array &$components, int $depth): void
    {
        $this->guardDepth($depth);

        foreach ($structure->rows as $row) {
            /** @var BomRow $row */
            $componentId = (int) $row->component_article_id;
            $needed = BigRational::ofFraction($row->grossMilli(), 1)->multipliedBy($quantity)->dividedBy($structure->yield_milli);
            $sub = $this->validOn($componentId, $date);

            if ($sub !== null) {
                $this->explodeInto($sub, $needed, $date, $components, $depth + 1);

                continue;
            }

            $components[$componentId] = ($components[$componentId] ?? BigRational::zero())->plus($needed);
        }
    }

    private function costAt(BillOfMaterials $structure, UnitCosts $costs, CarbonImmutable $date, int $depth): StructureCost
    {
        $this->guardDepth($depth);
        $rows = [];
        $batch = 0;
        $complete = true;

        foreach ($structure->rows as $row) {
            /** @var BomRow $row */
            $componentId = (int) $row->component_article_id;
            $gross = $row->grossMilli();
            $unit = $this->unitCostAt($componentId, $costs, $date, $depth + 1);
            $line = $unit['ore'] === null ? null : Quantity::value($gross, $unit['ore']);

            if ($line === null) {
                $complete = false;
            } else {
                $batch += $line;
            }

            $rows[] = [
                'row' => $row,
                'gross_milli' => $gross,
                'unit_cost_ore' => $unit['ore'],
                'source' => $unit['source'],
                'line_cost_ore' => $line,
                'latest' => $costs->latest($componentId),
            ];
        }

        return new StructureCost($structure, $rows, $batch, StructureMath::perUnit($batch, $structure->yield_milli), $complete);
    }

    /** @return array{ore: ?int, source: ?string} */
    private function unitCostAt(int $articleId, UnitCosts $costs, CarbonImmutable $date, int $depth): array
    {
        $structure = $this->validOn($articleId, $date);

        if ($structure === null) {
            return $costs->of($articleId);
        }

        $cost = $this->costAt($structure, $costs, $date, $depth);

        return $cost->complete ? ['ore' => $cost->perUnitOre, 'source' => StructureCost::SOURCE] : ['ore' => null, 'source' => null];
    }

    private function guardDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new LogicException('The structure is nested too deep or loops.');
        }
    }
}
