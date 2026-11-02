<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use Carbon\CarbonImmutable;

/**
 * The standard VAT rate of each member state other than Sweden, in basis
 * points, for sales to consumers under the One Stop Shop (OSS). A rate
 * applies from its date until the next one for the same country.
 *
 * Only standard rates: a sale that has a reduced rate in the buyer's country
 * cannot be invoiced through OSS here yet.
 *
 * TODO(legal-verify): every rate and date below must be checked against the
 * European Commission's rate database (TEDB) before use; reduced rates are
 * missing. The threshold under which such sales carry Swedish VAT instead
 * (99 680 kronor, ML 6 kap. 62 §) is in OssThreshold.
 */
final class OssRates
{
    /** @var array<string, array<string, int>> country => [from date => basis points] */
    public const array STANDARD = [
        'AT' => ['2021-07-01' => 2000],
        'BE' => ['2021-07-01' => 2100],
        'BG' => ['2021-07-01' => 2000],
        'CY' => ['2021-07-01' => 1900],
        'CZ' => ['2021-07-01' => 2100],
        'DE' => ['2021-07-01' => 1900],
        'DK' => ['2021-07-01' => 2500],
        'EE' => ['2021-07-01' => 2000, '2024-01-01' => 2200, '2025-07-01' => 2400],
        'ES' => ['2021-07-01' => 2100],
        'FI' => ['2021-07-01' => 2400, '2024-09-01' => 2550],
        'FR' => ['2021-07-01' => 2000],
        'GR' => ['2021-07-01' => 2400],
        'HR' => ['2021-07-01' => 2500],
        'HU' => ['2021-07-01' => 2700],
        'IE' => ['2021-07-01' => 2100, '2021-09-01' => 2300],
        'IT' => ['2021-07-01' => 2200],
        'LT' => ['2021-07-01' => 2100],
        'LU' => ['2021-07-01' => 1700],
        'LV' => ['2021-07-01' => 2100],
        'MT' => ['2021-07-01' => 1800],
        'NL' => ['2021-07-01' => 2100],
        'PL' => ['2021-07-01' => 2300],
        'PT' => ['2021-07-01' => 2300],
        'RO' => ['2021-07-01' => 1900, '2025-08-01' => 2100],
        'SI' => ['2021-07-01' => 2200],
        'SK' => ['2021-07-01' => 2000, '2025-01-01' => 2300],
    ];

    /** Output VAT under OSS, kept out of the Swedish momsdeklaration and its settlement. */
    public const int VAT_ACCOUNT = 2619;

    public static function standard(string $country, CarbonImmutable $date): ?int
    {
        $rate = null;

        foreach (self::STANDARD[strtoupper($country)] ?? [] as $from => $basisPoints) {
            if ($date->toDateString() >= $from) {
                $rate = $basisPoints;
            }
        }

        return $rate;
    }
}
