<?php

declare(strict_types=1);

namespace App\Support\Currency;

/**
 * Kronor per one unit of a currency on a day, kept as an integer number of
 * millionths so amounts are converted without floats.
 */
final readonly class ExchangeRate
{
    public const int SCALE = 1_000_000;

    public function __construct(
        public string $currency,
        public string $date,
        public int $micros,
        public string $source,
    ) {}

    /**
     * A rate typed by a person: "9,8264", "9.8264" or "10". Null when it is
     * not a positive number with at most six decimals.
     */
    public static function parse(string $currency, string $value, string $date = '', string $source = 'manual'): ?self
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        if (preg_match('/^(\d{1,6})(?:\.(\d{1,6}))?$/', $value, $match) !== 1) {
            return null;
        }

        $micros = (int) $match[1] * self::SCALE + (int) str_pad($match[2] ?? '', 6, '0');

        return $micros > 0 ? new self($currency, $date, $micros, $source) : null;
    }

    /** Hundredths of the currency to öre, rounded half up. */
    public function toOre(int $hundredths): int
    {
        $sign = $hundredths < 0 ? -1 : 1;
        $product = abs($hundredths) * $this->micros;

        return $sign * intdiv($product + intdiv(self::SCALE, 2), self::SCALE);
    }

    /** The rate as people write it in Sweden: "9,826350". */
    public function format(): string
    {
        return intdiv($this->micros, self::SCALE).','.str_pad((string) ($this->micros % self::SCALE), 6, '0', STR_PAD_LEFT);
    }
}
