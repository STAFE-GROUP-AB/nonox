<?php

declare(strict_types=1);

namespace App\Domain\Units;

use Brick\Math\BigRational;

/**
 * Conversions derived from a material's nominal size (ADR-071 §12.3).
 * Sawn timber and board carry length L, width W and thickness T in
 * micrometres. Every unit is expressed as the metres of running piece it
 * stands for (1 lpm = 1 m, 1 m² = 1/W m, 1 m³ = 1/(W×T) m, 1 st = L m);
 * a pair converts when the dimensions it needs are set. This yields the
 * table of §12.3 (and m³ to m² for a thin stock unit such as veneer).
 */
final class MaterialConversions
{
    /** The geometric units a conversion is derived to, in this order. */
    public const array UNITS = ['lpm', 'm', 'm²', 'm³', 'st'];

    /**
     * Entered unit code => how much of that unit one stock unit is.
     *
     * @return array<string, BigRational>
     */
    public static function for(string $stockCode, ?int $lengthUm, ?int $widthUm, ?int $thicknessUm): array
    {
        $stock = UnitDefaults::codeFor($stockCode);
        $thickness = self::metresOf($thicknessUm);

        // Area and volume of the same board need only the thickness: 1 m² = T m³ (veneer counted in m³ and used in m²).
        $byThickness = match (true) {
            $thickness === null => [],
            $stock === 'm²' => ['m³' => $thickness],
            $stock === 'm³' => ['m²' => BigRational::one()->dividedBy($thickness)],
            default => [],
        };

        $stockMetres = self::metres($stock, $lengthUm, $widthUm, $thicknessUm);

        if ($stock === null || $stockMetres === null) {
            return $byThickness;
        }

        $stockGroup = UnitDefaults::dimension($stock)?->group();
        $result = [];

        foreach (self::UNITS as $code) {
            if ($code === $stock || UnitDefaults::dimension($code)?->group() === $stockGroup) {
                continue;
            }

            $unitMetres = self::metres($code, $lengthUm, $widthUm, $thicknessUm);

            if ($unitMetres !== null) {
                $result[$code] = $stockMetres->dividedBy($unitMetres);
            }
        }

        return [...$byThickness, ...$result];
    }

    /** The metres of running piece one of the unit stands for, or null when a needed dimension is missing. */
    private static function metres(?string $code, ?int $lengthUm, ?int $widthUm, ?int $thicknessUm): ?BigRational
    {
        $length = self::metresOf($lengthUm);
        $width = self::metresOf($widthUm);
        $thickness = self::metresOf($thicknessUm);

        return match ($code) {
            'lpm', 'm' => BigRational::one(),
            'm²' => $width === null ? null : BigRational::one()->dividedBy($width),
            'm³' => $width === null || $thickness === null ? null : BigRational::one()->dividedBy($width->multipliedBy($thickness)),
            'st' => $length,
            default => null,
        };
    }

    private static function metresOf(?int $micrometres): ?BigRational
    {
        return $micrometres === null || $micrometres <= 0 ? null : BigRational::of($micrometres)->dividedBy(1_000_000);
    }
}
