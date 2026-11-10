<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Support;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\QuantityRoundsToZero;
use App\Domain\Units\UnitConverter;
use App\Models\SalesDocumentRow;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Inventory\Domain\StructureMath;
use App\Modules\Manufacturing\Models\RowStructure;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Illuminate\Support\Collection;

/**
 * The costing of a quote or an order (ADR-071 §6), internal and never on
 * the customer's PDF: per row with a bill of materials copy, the quantity
 * in the output's stock unit, the cost per unit and for the row, the
 * contribution and the margin against the row's net amount; and the same
 * summed over those rows. Rows without a copy have no cost and are left
 * out of the sums, which the page says.
 */
final readonly class DocumentCosting
{
    /**
     * @param  array<string, array{row: SalesDocumentRow, structure: RowStructure, costing: Costing, quantity_milli: ?int, cost_ore: ?int, net_ore: int, contribution_ore: ?int, margin_bp: ?int}>  $rows  by line_key
     */
    public function __construct(
        public array $rows,
        public int $netOre,
        public int $costOre,
        public bool $complete,
    ) {}

    /**
     * @param  Collection<int, SalesDocumentRow>  $rows  the document's rows
     * @param  Collection<string, RowStructure>  $structures  by line_key, with lines and the output article with its stock unit loaded
     * @param  array<int, array<string, BigRational>>  $conversions  output article => its conversions (ArticleUnits::fractions)
     */
    public static function of(Collection $rows, Collection $structures, ManufacturingBook $book, array $conversions = []): self
    {
        $result = [];
        $net = 0;
        $cost = 0;
        $complete = true;

        foreach ($rows as $row) {
            $structure = $row->line_key === null ? null : $structures->get($row->line_key);

            if (! $structure instanceof RowStructure) {
                continue;
            }

            $costing = $book->cost($structure->lines, $structure->yield_milli);
            $quantity = self::quantityInStock($row, $structure, $conversions[$structure->output_article_id] ?? []);
            $rowCost = $quantity === null || ! $costing->complete ? null : Quantity::value($quantity, $costing->perUnitOre());
            $rowNet = $row->net_ore->ore;
            $contribution = $rowCost === null ? null : $rowNet - $rowCost;

            if ($rowCost === null) {
                $complete = false;
            } else {
                $net += $rowNet;
                $cost += $rowCost;
            }

            $result[$row->line_key] = [
                'row' => $row,
                'structure' => $structure,
                'costing' => $costing,
                'quantity_milli' => $quantity,
                'cost_ore' => $rowCost,
                'net_ore' => $rowNet,
                'contribution_ore' => $contribution,
                'margin_bp' => $contribution === null ? null : StructureMath::share($contribution, $rowNet),
            ];
        }

        return new self($result, $net, $cost, $complete);
    }

    public function contributionOre(): int
    {
        return $this->netOre - $this->costOre;
    }

    public function marginBp(): ?int
    {
        return StructureMath::share($this->contributionOre(), $this->netOre);
    }

    /**
     * The row's quantity in thousandths of the output's stock unit: as it
     * is when the row's unit is the stock unit (or empty), converted when
     * the article converts the row's unit, null when it does not.
     */
    /** @param  array<string, BigRational>  $conversions */
    private static function quantityInStock(SalesDocumentRow $row, RowStructure $structure, array $conversions): ?int
    {
        $output = $structure->outputArticle;
        $quantity = BigDecimal::of($row->quantity);
        $negative = $quantity->isNegative();
        $quantity = $quantity->abs();

        if ($output === null || $quantity->isZero()) {
            return $quantity->isZero() ? 0 : null;
        }

        $perStock = UnitConverter::enteredPerStock(ArticleUnits::stockCode($output), $row->unit, $conversions);

        if ($perStock === null) {
            return null;
        }

        try {
            $milli = UnitConverter::toStock($quantity, $perStock)->stockMilli;
        } catch (QuantityRoundsToZero) {
            return 0;
        }

        return $negative ? -$milli : $milli;
    }
}
