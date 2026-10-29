<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The amounts of an invoice. Each row is quantity × price less the discount,
 * rounded to öre. VAT is calculated per rate on the sum of the rows at that
 * rate, so rows never add up to a rounding difference. The total is rounded
 * to whole kronor (öresavrundning), and the difference is kept separately.
 */
final class InvoiceCalculator
{
    /** The VAT rates in basis points that an invoice row may have. */
    public const array VAT_RATES = [2500, 1200, 600, 0];

    public static function rowNet(Money $unitPrice, string $quantity, int $discountBasisPoints): Money
    {
        $ore = BigDecimal::of($unitPrice->ore)
            ->multipliedBy($quantity)
            ->multipliedBy(10000 - $discountBasisPoints)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();

        return Money::ofOre($ore);
    }

    /**
     * @param  array<int, array{net: int, vat_rate: int}>  $rows  net in öre
     * @return array{net: int, vat: int, rounding: int, total: int, vat_by_rate: array<int, array{base: int, vat: int}>}
     */
    public static function totals(array $rows): array
    {
        $byRate = [];

        foreach ($rows as $row) {
            $byRate[$row['vat_rate']] ??= ['base' => 0, 'vat' => 0];
            $byRate[$row['vat_rate']]['base'] += $row['net'];
        }

        krsort($byRate);

        foreach ($byRate as $rate => $group) {
            $byRate[$rate]['vat'] = Money::ofOre($group['base'])->percent($rate)->ore;
        }

        $net = array_sum(array_column($byRate, 'base'));
        $vat = array_sum(array_column($byRate, 'vat'));
        $total = Money::ofOre($net + $vat)->roundedToKronor()->ore;

        return [
            'net' => $net,
            'vat' => $vat,
            'rounding' => $total - $net - $vat,
            'total' => $total,
            'vat_by_rate' => $byRate,
        ];
    }

    /**
     * A discount in percent as written ("10", "12,5"), in basis points, or
     * null when it cannot be read or is not between 0 and 100.
     */
    public static function discount(string $input): ?int
    {
        $value = str_replace(',', '.', trim($input));

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        $basisPoints = BigDecimal::of($value)->multipliedBy(100)->toInt();

        return $basisPoints <= 10000 ? $basisPoints : null;
    }

    /**
     * A quantity as written by a person ("2", "1,5", "0.25"), as a decimal
     * string with up to three decimals, or null when it cannot be read.
     */
    public static function quantity(string $input): ?string
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($input));

        return preg_match('/^-?\d{1,9}(\.\d{1,3})?$/', $value) === 1 ? $value : null;
    }
}
