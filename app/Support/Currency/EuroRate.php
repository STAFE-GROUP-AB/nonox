<?php

declare(strict_types=1);

namespace App\Support\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Units of a currency per one euro on a day (an ECB euro reference rate),
 * kept as millionths so amounts are converted without floats.
 */
final readonly class EuroRate
{
    public const int SCALE = 1_000_000;

    public function __construct(
        public string $currency,
        public string $date,
        public int $micros,
    ) {}

    /** Hundredths of the currency to euro cents, rounded half up. */
    public function toCents(int $hundredths): int
    {
        return BigDecimal::of($hundredths)->multipliedBy(self::SCALE)->dividedBy($this->micros, 0, RoundingMode::HalfUp)->toInt();
    }

    /** The rate as people write it in Sweden, with at least four decimals: "11,3310". */
    public function format(): string
    {
        $decimals = rtrim(str_pad((string) ($this->micros % self::SCALE), 6, '0', STR_PAD_LEFT), '0');

        return intdiv($this->micros, self::SCALE).','.str_pad($decimals, 4, '0');
    }
}
