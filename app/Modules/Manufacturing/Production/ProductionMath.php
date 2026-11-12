<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Production;

use App\Modules\Inventory\Domain\StructureMath;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The arithmetic of a production order (ADR-071 §8, §12.4), in integers
 * with one half-up rounding each:
 *
 * - what the order needs of a component: the batch's gross quantity (loss
 *   included, RS-C2) × the order's quantity ÷ the batch, in thousandths of
 *   the component's stock unit, never below one thousandth;
 * - the planned minutes of an operation for the order: the batch's minutes
 *   × the order's quantity ÷ the batch;
 * - the overhead, when the company includes it: basis points of material
 *   and labour;
 * - the receipt's cost per stock unit: the manufacturing cost ÷ the
 *   produced quantity.
 */
final class ProductionMath
{
    public static function required(int $netMilli, int $lossBp, int $orderMilli, int $yieldMilli): int
    {
        $gross = StructureMath::gross($netMilli, $lossBp);

        return max(1, BigDecimal::of($gross)->multipliedBy($orderMilli)->dividedBy($yieldMilli, 0, RoundingMode::HalfUp)->toInt());
    }

    public static function plannedMinutes(int $batchMinutes, int $orderMilli, int $yieldMilli): int
    {
        return BigDecimal::of($batchMinutes)->multipliedBy($orderMilli)->dividedBy($yieldMilli, 0, RoundingMode::HalfUp)->toInt();
    }

    public static function overhead(int $materialOre, int $labourOre, int $overheadBp): int
    {
        if ($overheadBp <= 0) {
            return 0;
        }

        return BigDecimal::of($materialOre + $labourOre)->multipliedBy($overheadBp)->dividedBy(StructureMath::BP, 0, RoundingMode::HalfUp)->toInt();
    }

    public static function unitCost(int $totalOre, int $producedMilli): int
    {
        return BigDecimal::of($totalOre)->multipliedBy(1000)->dividedBy($producedMilli, 0, RoundingMode::HalfUp)->toInt();
    }
}
