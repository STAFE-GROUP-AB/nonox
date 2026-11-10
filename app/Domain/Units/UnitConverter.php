<?php

declare(strict_types=1);

namespace App\Domain\Units;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Conversion between an article's stock unit and the unit a quantity is
 * typed in (ADR-071 §12.2 and §12.4). Fractions are exact (BigRational);
 * stock quantities are whole milli-units of the stock unit, rounded half
 * up once, at entry. No floats anywhere.
 */
final class UnitConverter
{
    /** Round trips that change the typed quantity by more than this (0,5 %) warn. */
    public const string WARN_ABOVE = '0.005';

    /**
     * How much of the entered unit one stock unit is, as an exact fraction;
     * null when the two units do not convert. Order: same unit, the article's
     * own conversion (manual or derived), then the built-in conversion
     * between units of the same dimension.
     *
     * @param  array<string, BigRational>  $conversions  the article's conversions, entered unit code => entered per stock unit
     */
    public static function enteredPerStock(string $stockCode, string $enteredCode, array $conversions = []): ?BigRational
    {
        $stock = UnitDefaults::codeFor($stockCode) ?? UnitDefaults::normalise($stockCode);
        $entered = UnitDefaults::codeFor($enteredCode) ?? UnitDefaults::normalise($enteredCode);

        if ($entered === '' || $entered === $stock) {
            return BigRational::one();
        }

        if (isset($conversions[$entered])) {
            return $conversions[$entered];
        }

        $stockBase = UnitDefaults::base($stock);
        $enteredBase = UnitDefaults::base($entered);

        if ($stockBase === null || $enteredBase === null) {
            return null;
        }

        if (UnitDefaults::dimension($stock)?->group() !== UnitDefaults::dimension($entered)?->group()) {
            return null;
        }

        return $stockBase->dividedBy($enteredBase);
    }

    /**
     * Turns what was typed (a decimal, any number of places) into milli-units
     * of the stock unit: round_half_up(entered × 1000 / enteredPerStock).
     *
     * @throws QuantityRoundsToZero when the result is zero
     * @throws QuantityOutOfRange when it does not fit a stock quantity
     */
    public static function toStock(BigDecimal|string $entered, BigRational $enteredPerStock): QuantityConversion
    {
        $entered = BigDecimal::of($entered);
        try {
            $stockMilli = BigRational::of($entered)->multipliedBy(1000)->dividedBy($enteredPerStock)
                ->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw new QuantityOutOfRange;
        }

        if ($stockMilli <= 0) {
            throw new QuantityRoundsToZero;
        }

        $roundTrip = BigRational::of($stockMilli)->dividedBy(1000)->multipliedBy($enteredPerStock);
        $difference = $roundTrip->minus($entered)->abs();
        $warns = $difference->compareTo(BigRational::of($entered)->multipliedBy(self::WARN_ABOVE)) > 0;
        $percent = str_replace('.', ',', (string) $difference->dividedBy($entered)->multipliedBy(100)->toScale(2, RoundingMode::HalfUp));

        return new QuantityConversion($stockMilli, $roundTrip->toScale(12, RoundingMode::HalfUp), $warns, $percent);
    }

    /**
     * The exact value of a line: quantity in the line's own unit × the price
     * per that unit, in öre, rounded half up once (F-U1c, F-U5). Kept apart
     * from the stock ledger until the accounting check of §12.4 is done.
     */
    public static function lineValueOre(BigDecimal|string $quantity, int $priceOrePerUnit): int
    {
        return BigDecimal::of($quantity)->multipliedBy($priceOrePerUnit)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /** Cost per whole stock unit in öre from a line value and the stock quantity, half up. */
    public static function unitCostOre(int $valueOre, int $stockMilli): int
    {
        return BigDecimal::of($valueOre)->multipliedBy(1000)->dividedBy($stockMilli, 0, RoundingMode::HalfUp)->toInt();
    }
}
