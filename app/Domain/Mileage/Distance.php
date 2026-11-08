<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Distances in the log are hectometres (0,1 km). One mil is 100 hm, so a
 * rate in öre per mil times hm / 100 is exact before the one rounding.
 */
final class Distance
{
    public const int HM_PER_KM = 10;

    public const int HM_PER_MIL = 100;

    /**
     * Reads a distance in km as typed in Sweden: "42", "118,5", "1 240" or "12.5".
     *
     * @throws InvalidArgumentException when it is not a number with at most one decimal
     */
    public static function parseKm(string $km): int
    {
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($km));

        if (preg_match('/^\d+(\.\d)?$/', $clean) !== 1) {
            throw new InvalidArgumentException("Not a distance: {$km}");
        }

        return BigDecimal::of($clean)->multipliedBy(self::HM_PER_KM)->toInt();
    }

    /** "12,5 km", "1 240 km", "−42 km" */
    public static function formatKm(int $hm): string
    {
        $sign = $hm < 0 ? '−' : '';
        $whole = number_format(intdiv(abs($hm), self::HM_PER_KM), 0, ',', ' ');
        $decimal = abs($hm) % self::HM_PER_KM;

        return $decimal === 0 ? "{$sign}{$whole} km" : "{$sign}{$whole},{$decimal} km";
    }

    /** "17.25" mil, the payroll quantity. */
    public static function mil(int $hm): string
    {
        return (string) BigDecimal::of($hm)->dividedBy(self::HM_PER_MIL, 2, RoundingMode::Unnecessary);
    }

    /** "17,25 mil" */
    public static function formatMil(int $hm): string
    {
        return str_replace('.', ',', self::mil($hm)).' '.__('mil');
    }

    /** A rate in öre per mil times the distance, rounded half up to whole öre once. */
    public static function amountOre(int $hm, int $orePerMil): int
    {
        return BigDecimal::of($hm)->multipliedBy($orePerMil)->dividedBy(self::HM_PER_MIL, 0, RoundingMode::HalfUp)->toInt();
    }
}
