<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Currency\EuroRate;
use App\Support\Currency\EuroReferenceRates;

/**
 * ECB euro reference rates for tests, so no test reaches the network. Bound
 * for every test in Tests\TestCase; a test adds the rates it needs with
 * FakeEuroRates::set().
 */
final class FakeEuroRates implements EuroReferenceRates
{
    /** @var array<string, array<string, int>> currency => [date => micros] */
    private array $rates = [];

    /** Units of $currency per euro published on $date, "11,3310". */
    public static function set(string $currency, string $date, string $rate): void
    {
        $fake = app(EuroReferenceRates::class);

        if (! $fake instanceof self) {
            throw new \LogicException('FakeEuroRates is not bound.');
        }

        [$whole, $decimals] = array_pad(explode(',', $rate), 2, '');
        $fake->rates[strtoupper($currency)][$date] = (int) $whole * EuroRate::SCALE + (int) str_pad($decimals, 6, '0');
    }

    public function onOrAfter(string $currency, string $date): ?EuroRate
    {
        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return new EuroRate('EUR', $date, EuroRate::SCALE);
        }

        $published = array_filter($this->rates[$currency] ?? [], fn (string $day): bool => $day >= $date, ARRAY_FILTER_USE_KEY);
        ksort($published);
        $day = array_key_first($published);

        return $day === null ? null : new EuroRate($currency, (string) $day, $published[$day]);
    }
}
