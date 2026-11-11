<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use App\Domain\Units\UnitDefaults;
use App\Domain\Units\UnitDimension;
use App\Models\Article;
use App\Models\Unit;
use App\Modules\Inventory\Models\ArticleUnitConversion;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Quantities and prices on purchase order rows (ADR-071 §12.2, §12.4): a
 * row is typed in any unit the article converts to, stored in milli-units
 * of the stock unit, and priced per its own unit. The article's purchase
 * price is per stock unit, so a default row price is that price in the
 * row's unit, rounded half up once.
 */
final class PurchaseQuantities
{
    /**
     * The conversions of the articles, in one query: article id => entered
     * unit code => how much of that unit one stock unit is.
     *
     * @param  array<int|string>  $articleIds  ids, also as model keys
     * @return array<int, array<string, BigRational>>
     */
    public static function conversions(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }

        return ArticleUnitConversion::query()->whereIn('article_id', $articleIds)->get()
            ->groupBy('article_id')
            ->map(fn ($group): array => ArticleUnits::fractions($group))
            ->all();
    }

    /**
     * How much of the unit one stock unit of the article is; null when the
     * unit does not convert to the article's stock unit.
     *
     * @param  array<string, BigRational>  $conversions  the article's own
     */
    public static function perStock(Article $article, Unit $unit, array $conversions): ?BigRational
    {
        return UnitConverter::enteredPerStock(ArticleUnits::stockCode($article), $unit->code, $conversions);
    }

    /** A price per stock unit as a price per the entered unit, half up. */
    public static function pricePerEntered(int $stockPriceOre, BigRational $perStock): int
    {
        return BigRational::of($stockPriceOre)->dividedBy($perStock)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * A stock quantity expressed in the purchase unit, rounded up: to whole
     * units for things that are counted (st, förp, par, set) and for a unit
     * without a fixed size (one the company added, such as rulle: a pack is
     * bought whole), otherwise to a thousandth.
     */
    public static function inPurchaseUnit(int $stockMilli, BigRational $perStock, Unit $unit): BigDecimal
    {
        $exact = BigRational::of($stockMilli)->dividedBy(1000)->multipliedBy($perStock);

        if ($unit->dimension === UnitDimension::Count || UnitDefaults::base(UnitDefaults::codeFor($unit->code) ?? UnitDefaults::normalise($unit->code)) === null) {
            return $exact->toScale(0, RoundingMode::Up);
        }

        return $exact->toScale(3, RoundingMode::Up);
    }
}
