<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Domain\Units\ArticleUnits;
use App\Domain\Units\UnitConverter;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Units on a structure row (rule sheet RS-C1). Rows are stored in the
 * component article's stock unit. A unit of the same dimension converts
 * with the built-in factors of the unit catalogue (g for kg; ml, cl and dl
 * for l; ADR-071 §12.1); any other unit needs a conversion on the article
 * (article_unit_conversions). The factors are the thousandths form kept for
 * callers that still use it; UnitConverter works with exact fractions.
 */
final class StructureUnits
{
    public static function normalise(string $unit): string
    {
        return mb_strtolower(trim($unit));
    }

    /**
     * The factor that turns the entered unit into the article's unit, or
     * null when there is none. 1000 when the units are the same.
     *
     * @param  array<string, int>  $conversions  the article's own conversions, unit => factor_milli
     */
    public static function factor(string $articleUnit, string $enteredUnit, array $conversions = []): ?int
    {
        $fractions = array_map(fn (int $factor): BigRational => BigRational::of($factor)->dividedBy(Quantity::SCALE), $conversions);
        $fraction = UnitConverter::enteredPerStock($articleUnit, $enteredUnit, $fractions);

        return $fraction?->multipliedBy(Quantity::SCALE)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** Thousandths of the entered unit as thousandths of the article's unit, rounded half up. */
    public static function convert(int $enteredMilli, int $factorMilli): int
    {
        return BigRational::of($enteredMilli)->multipliedBy(Quantity::SCALE)->dividedBy($factorMilli)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * The units a row of this article may be entered in.
     *
     * @param  array<string, mixed>  $conversions
     * @return list<string>
     */
    public static function choices(string $articleUnit, array $conversions = []): array
    {
        return ArticleUnits::choices($articleUnit, $conversions);
    }
}
