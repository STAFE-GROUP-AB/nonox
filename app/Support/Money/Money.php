<?php

declare(strict_types=1);

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of money in whole öre (AGENTS.md §1.2, §6.3). Never a float.
 * Immutable: every operation returns a new instance.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private const string NBSP = "\u{00A0}";

    private function __construct(public int $ore, public string $currency) {}

    public static function ofOre(int $ore, string $currency = 'SEK'): self
    {
        return new self($ore, self::currency($currency));
    }

    public static function zero(string $currency = 'SEK'): self
    {
        return self::ofOre(0, $currency);
    }

    /**
     * Parses amounts the way people type them in Sweden: "1234,50",
     * "1 234,50", "1234.50", "-1 234,5", "1234" and an optional "kr".
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $input, string $currency = 'SEK'): self
    {
        $value = str_replace([self::NBSP, "\u{202F}", ' ', "\u{2212}"], ['', '', '', '-'], trim($input));
        $value = (string) preg_replace('/kr\.?$|sek$/i', '', $value);

        if (! preg_match('/^(-?)(\d+)(?:[.,](\d{1,2}))?$/', $value, $parts)) {
            throw new InvalidArgumentException("Could not read the amount \"{$input}\".");
        }

        $ore = BigDecimal::of($parts[2].'.'.str_pad($parts[3] ?? '', 2, '0'))
            ->multipliedBy(100)
            ->toScale(0)
            ->toInt();

        return self::ofOre($parts[1] === '-' ? -$ore : $ore, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->ore + $other->ore, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->ore - $other->ore, $this->currency);
    }

    /**
     * Multiplies by a quantity or factor such as "1.5" or 3, rounding to whole
     * öre. Pass decimals as strings so they are never floats.
     */
    public function times(int|string $factor, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        $ore = BigDecimal::of($this->ore)
            ->multipliedBy($factor)
            ->toScale(0, $rounding)
            ->toInt();

        return new self($ore, $this->currency);
    }

    /**
     * A percentage given in basis points (2500 = 25 %), as used for VAT rates
     * (AGENTS.md §8.1).
     */
    public function percent(int $basisPoints, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        $ore = BigDecimal::of($this->ore)
            ->multipliedBy($basisPoints)
            ->dividedBy(10000, 0, $rounding)
            ->toInt();

        return new self($ore, $this->currency);
    }

    /**
     * The VAT contained in an amount that includes VAT, for a rate in basis
     * points: 1 250 kr at 2500 (25 %) contains 250 kr, i.e. amount * rate / (1 + rate).
     */
    public function vatIncluded(int $basisPoints, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        $ore = BigDecimal::of($this->ore)
            ->multipliedBy($basisPoints)
            ->dividedBy(10000 + $basisPoints, 0, $rounding)
            ->toInt();

        return new self($ore, $this->currency);
    }

    /**
     * Rounds to whole kronor (öresavrundning).
     */
    public function roundedToKronor(RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        $kronor = BigDecimal::of($this->ore)->dividedBy(100, 0, $rounding)->toInt();

        return new self($kronor * 100, $this->currency);
    }

    public function negated(): self
    {
        return new self(-$this->ore, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->ore === 0;
    }

    public function isNegative(): bool
    {
        return $this->ore < 0;
    }

    public function equals(self $other): bool
    {
        return $this->ore === $other->ore && $this->currency === $other->currency;
    }

    /**
     * Swedish formatting: "1 234,50 kr", "-1 234,50 kr". The spaces are
     * non-breaking so an amount never wraps across two lines.
     */
    public function format(bool $withCurrency = true): string
    {
        $absolute = abs($this->ore);
        $kronor = number_format(intdiv($absolute, 100), 0, ',', self::NBSP);
        $ore = str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $sign = $this->ore < 0 ? '-' : '';
        $suffix = $withCurrency ? self::NBSP.($this->currency === 'SEK' ? 'kr' : $this->currency) : '';

        return "{$sign}{$kronor},{$ore}{$suffix}";
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** @return array{ore: int, currency: string} */
    public function jsonSerialize(): array
    {
        return ['ore' => $this->ore, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} and {$other->currency}.");
        }
    }

    private static function currency(string $currency): string
    {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("\"{$currency}\" is not an ISO 4217 currency code.");
        }

        return $currency;
    }
}
