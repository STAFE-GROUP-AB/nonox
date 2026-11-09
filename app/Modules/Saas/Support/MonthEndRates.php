<?php

declare(strict_types=1);

namespace App\Modules\Saas\Support;

use App\Modules\Saas\Domain\ContractVersion;
use App\Modules\Saas\Domain\Months;
use App\Support\Currency\RiksbankRates;
use Carbon\CarbonImmutable;

/**
 * Riksbanken's mid rate for the last banking day of each month (SA-R12), per
 * currency the contracts use. A closed month keeps the rates it was closed
 * with, so a later lookup can never change it. The current month uses the
 * latest rate published up to today. Contracts in kronor need no rate.
 */
final class MonthEndRates
{
    public function __construct(private readonly RiksbankRates $riksbank) {}

    /**
     * @param  list<ContractVersion>  $versions
     * @param  list<string>  $months
     * @param  array<string, array<string, int>>  $frozen  month => currency => micros, from closed months
     * @return array<string, array<string, int>>
     */
    public function for(array $versions, array $months, array $frozen, CarbonImmutable $today): array
    {
        $currencies = [];

        foreach ($versions as $version) {
            foreach ($version->lines as $line) {
                if ($line->currency !== 'SEK') {
                    $currencies[$line->currency] = true;
                }
            }
        }

        $rates = [];

        foreach ($months as $month) {
            if (isset($frozen[$month])) {
                $rates[$month] = $frozen[$month];

                continue;
            }

            foreach (array_keys($currencies) as $currency) {
                $day = min(Months::end($month), $today->toDateString());
                $rate = $this->riksbank->rate($currency, $day);

                if ($rate !== null) {
                    $rates[$month][$currency] = $rate->micros;
                }
            }
        }

        return $rates;
    }
}
