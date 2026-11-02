<?php

declare(strict_types=1);

namespace App\Support\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The ECB's euro foreign exchange reference rates from its data portal
 * (series EXR.D.{currency}.EUR.SP00.A: daily, on TARGET working days), free
 * and without a key. The base URL is config('services.ecb.url').
 *
 * A rate once found never changes, so it is cached for good; a failed or
 * empty lookup is remembered for ten minutes so a page that shows it does
 * not wait on the source every time.
 */
final class EcbRates implements EuroReferenceRates
{
    /** How many days after the date a published rate is looked for. */
    private const int LOOKAHEAD_DAYS = 10;

    public function onOrAfter(string $currency, string $date): ?EuroRate
    {
        $currency = strtoupper($currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return null;
        }

        if ($currency === 'EUR') {
            return new EuroRate('EUR', $date, EuroRate::SCALE);
        }

        $day = CarbonImmutable::parse($date)->startOfDay();

        if ($day->isAfter(CarbonImmutable::today())) {
            return null;
        }

        $key = "ecb-rate:{$currency}:{$day->toDateString()}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return new EuroRate($currency, (string) $cached['date'], (int) $cached['micros']);
        }

        if (Cache::has("{$key}:miss")) {
            return null;
        }

        $rate = $this->fetch($currency, $day);

        $rate !== null
            ? Cache::forever($key, ['date' => $rate->date, 'micros' => $rate->micros])
            : Cache::put("{$key}:miss", true, now()->addMinutes(10));

        return $rate;
    }

    private function fetch(string $currency, CarbonImmutable $day): ?EuroRate
    {
        try {
            $csv = Http::baseUrl((string) config('services.ecb.url'))
                ->accept('text/csv')
                ->timeout(5)
                ->get("data/EXR/D.{$currency}.EUR.SP00.A", [
                    'startPeriod' => $day->toDateString(),
                    'endPeriod' => $day->addDays(self::LOOKAHEAD_DAYS)->toDateString(),
                    'format' => 'csvdata',
                ])
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException) {
            return null;
        }

        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $csv) ?: [], fn (string $line): bool => trim($line) !== ''));

        if (count($lines) < 2) {
            return null;
        }

        $header = str_getcsv($lines[0], escape: '');
        $dateColumn = array_search('TIME_PERIOD', $header, true);
        $valueColumn = array_search('OBS_VALUE', $header, true);

        if ($dateColumn === false || $valueColumn === false) {
            return null;
        }

        $observations = [];

        foreach (array_slice($lines, 1) as $line) {
            $fields = str_getcsv($line, escape: '');
            $observed = (string) ($fields[$dateColumn] ?? '');
            $value = trim((string) ($fields[$valueColumn] ?? ''));

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $observed) === 1 && $observed >= $day->toDateString() && is_numeric($value)) {
                $observations[$observed] = $value;
            }
        }

        if ($observations === []) {
            return null;
        }

        ksort($observations);
        $first = (string) array_key_first($observations);
        $micros = BigDecimal::of($observations[$first])->multipliedBy(EuroRate::SCALE)->toScale(0, RoundingMode::HalfUp)->toInt();

        return $micros > 0 ? new EuroRate($currency, $first, $micros) : null;
    }
}
