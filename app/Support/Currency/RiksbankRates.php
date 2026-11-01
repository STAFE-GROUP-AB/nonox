<?php

declare(strict_types=1);

namespace App\Support\Currency;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Riksbanken's exchange rates (SWEA, series SEK{currency}PMI: the daily mid
 * rate, kronor per unit). The base URL is config('services.riksbank.url').
 *
 * The rate for a day is the latest one published on or before it, since no
 * rates are published on weekends and holidays. A rate once found never
 * changes, so it is cached for good; the open API allows only a few calls a
 * minute. Null when Riksbanken has no rate or cannot be reached: the person
 * then types the rate.
 *
 * TODO(legal-verify): which rate a supplier invoice in a foreign currency is
 * booked at (Riksbanken's mid rate on the invoice date is used here), and
 * how exchange differences at payment are booked.
 */
class RiksbankRates
{
    /** How many days back a rate is looked for (long holidays). */
    private const int LOOKBACK_DAYS = 10;

    public function rate(string $currency, string $date): ?ExchangeRate
    {
        $currency = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $currency === 'SEK') {
            return null;
        }

        $day = CarbonImmutable::parse($date)->min(CarbonImmutable::today());
        $key = "riksbank-rate:{$currency}:{$day->toDateString()}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return new ExchangeRate($currency, (string) $cached['date'], (int) $cached['micros'], 'riksbank');
        }

        $rate = $this->fetch($currency, $day);

        if ($rate !== null) {
            Cache::forever($key, ['date' => $rate->date, 'micros' => $rate->micros]);
        }

        return $rate;
    }

    private function fetch(string $currency, CarbonImmutable $day): ?ExchangeRate
    {
        $from = $day->subDays(self::LOOKBACK_DAYS)->toDateString();

        try {
            $observations = Http::baseUrl((string) config('services.riksbank.url'))
                ->acceptJson()
                ->timeout(5)
                ->get("Observations/SEK{$currency}PMI/{$from}/{$day->toDateString()}")
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException) {
            return null;
        }

        $latest = is_array($observations) ? end($observations) : null;

        if (! is_array($latest) || ! is_numeric($latest['value'] ?? null) || ! is_string($latest['date'] ?? null)) {
            return null;
        }

        $micros = (int) round((float) $latest['value'] * ExchangeRate::SCALE);

        return $micros > 0 ? new ExchangeRate($currency, $latest['date'], $micros, 'riksbank') : null;
    }
}
