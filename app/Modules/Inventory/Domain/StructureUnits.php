<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Units on a structure row (rule sheet RS-C1). Rows are stored in the
 * component article's own unit. A smaller unit of the same dimension may
 * be entered with a fixed factor (g for kg; ml, cl and dl for l); any
 * other unit needs a conversion on the article (article_unit_conversions).
 */
final class StructureUnits
{
    /**
     * Fixed factors: article unit => entered unit => thousandths of the
     * entered unit in one article unit.
     */
    public const array FIXED = [
        'kg' => ['g' => 1_000_000],
        'l' => ['dl' => 10_000, 'cl' => 100_000, 'ml' => 1_000_000],
    ];

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
        $article = self::normalise($articleUnit);
        $entered = self::normalise($enteredUnit);

        if ($entered === '' || $entered === $article) {
            return Quantity::SCALE;
        }

        return $conversions[$entered] ?? self::FIXED[$article][$entered] ?? null;
    }

    /** Thousandths of the entered unit as thousandths of the article's unit, rounded half up. */
    public static function convert(int $enteredMilli, int $factorMilli): int
    {
        return BigDecimal::of($enteredMilli)->multipliedBy(Quantity::SCALE)->dividedBy($factorMilli, 0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * The units a row of this article may be entered in.
     *
     * @param  array<string, int>  $conversions
     * @return list<string>
     */
    public static function choices(string $articleUnit, array $conversions = []): array
    {
        $article = self::normalise($articleUnit);

        return array_values(array_unique([$article, ...array_keys(self::FIXED[$article] ?? []), ...array_keys($conversions)]));
    }
}
