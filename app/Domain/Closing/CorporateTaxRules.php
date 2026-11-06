<?php

declare(strict_types=1);

namespace App\Domain\Closing;

use App\Models\FiscalYear;
use LogicException;

/**
 * The rules behind the proposed income tax of a limited company or an
 * ekonomisk förening (ADR-056): the tax rate by the start of the
 * beskattningsår (IL 65 kap. 10 §), the rounding of the taxable income
 * (IL 1 kap. 7 §), the periodiseringsfond share and reversal period (IL 30
 * kap. 5 and 7 §§), the BAS accounts, and the BAS accounts that propose
 * INK2S adjustments. Data, not code: database/data/corporate-tax/rules.json,
 * every value with its source URL, quote and status. Nothing there is
 * legally verified; a year whose start matches no rate gets no proposal.
 */
final class CorporateTaxRules
{
    /** @var ?array<string, mixed> */
    private static ?array $data = null;

    /**
     * The rate in basis points (2060 = 20,6 %) for the fiscal year, chosen by
     * its start date, or null when the data has no rate for it.
     */
    public static function rateFor(FiscalYear $fiscalYear): ?int
    {
        return self::rateEntryFor($fiscalYear)['rate_bp'] ?? null;
    }

    /** @return ?array{starts_from: string, starts_before: ?string, rate_bp: int, status: string, read: string, source_url: string, source_quote: string} */
    public static function rateEntryFor(FiscalYear $fiscalYear): ?array
    {
        $start = $fiscalYear->start_date->toDateString();

        /** @var list<array{starts_from: string, starts_before: ?string, rate_bp: int, status: string, read: string, source_url: string, source_quote: string}> $rates */
        $rates = self::data()['rates'];

        foreach ($rates as $rate) {
            if ($start >= $rate['starts_from'] && ($rate['starts_before'] === null || $start < $rate['starts_before'])) {
                return $rate;
            }
        }

        return null;
    }

    /** @return array{value: mixed, status: string, read: string, source_url: string, source_quote: string} */
    public static function rule(string $key): array
    {
        /** @var array<string, array{value: mixed, status: string, read: string, source_url: string, source_quote: string}> $rules */
        $rules = self::data()['rules'];

        return $rules[$key] ?? throw new LogicException("Missing corporate tax rule {$key}.");
    }

    public static function int(string $key): int
    {
        $value = self::rule($key)['value'];

        return is_int($value) ? $value : throw new LogicException("Corporate tax rule {$key} is not a number.");
    }

    /** @return array<string, int> */
    public static function accounts(string $key): array
    {
        $value = self::rule($key)['value'];

        return is_array($value) ? array_map(fn (mixed $number): int => (int) $number, $value) : throw new LogicException("Corporate tax rule {$key} is not a list of accounts.");
    }

    /**
     * The INK2S adjustments proposed from BAS accounts: field code => accounts and source.
     *
     * @return array<int, array{label: string, accounts: list<int>, status: string, read: string, source_url: string, source_quote: string}>
     */
    public static function adjustments(): array
    {
        /** @var array<string, array{label: string, accounts: list<int>, status: string, read: string, source_url: string, source_quote: string}> $adjustments */
        $adjustments = self::data()['adjustments'];
        $byCode = [];

        foreach ($adjustments as $code => $adjustment) {
            $byCode[(int) $code] = $adjustment;
        }

        return $byCode;
    }

    /** @return array<string, mixed> */
    private static function data(): array
    {
        if (self::$data === null) {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) file_get_contents(database_path('data/corporate-tax/rules.json')), true, flags: JSON_THROW_ON_ERROR);
            self::$data = $data;
        }

        return self::$data;
    }
}
