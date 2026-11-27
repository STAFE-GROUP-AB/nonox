<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The change a mass price update makes to one price (ADR-082 §8), in integer
 * minor units: a percentage, a fixed amount, or the ratio of two index
 * figures the user types (decision 11; NONOX fetches no index), or a target
 * contribution margin over cost (priceFromCost; the cost is per line). The result
 * is rounded HalfUp to the minor unit once, then by the run's rounding rule
 * (§7), where a price above zero never rounds to zero.
 *
 * Stored on the run as ids and numbers only: {percent_bp}, {amount_minor} or
 * {index_old, index_new} as decimal strings with a point.
 */
final readonly class PriceUpdateRule
{
    private function __construct(
        public PriceUpdateKind $kind,
        public ?int $percentBp,
        public ?int $amountMinor,
        public ?BigDecimal $indexOld,
        public ?BigDecimal $indexNew,
        public ?int $marginBp = null,
    ) {}

    public static function percent(int $basisPoints): self
    {
        return new self(PriceUpdateKind::Percent, $basisPoints, null, null, null);
    }

    public static function amount(int $minor): self
    {
        return new self(PriceUpdateKind::Amount, null, $minor, null, null);
    }

    public static function index(BigDecimal $old, BigDecimal $new): self
    {
        if ($old->isLessThanOrEqualTo(0) || $new->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Index figures must be above zero.');
        }

        return new self(PriceUpdateKind::Index, null, null, $old, $new);
    }

    /**
     * The price that gives a contribution margin (täckningsgrad, the same
     * definition as RowMargin and the margin floor, ADR-082 §9): the target
     * share of the net price that is left after cost. From 0 up to but not
     * including 100 %; 100 % or more cannot be reached from a cost above zero.
     */
    public static function margin(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints >= 10000) {
            throw new InvalidArgumentException('A target margin must be from 0 % up to but not including 100 %.');
        }

        return new self(PriceUpdateKind::Margin, null, null, null, null, $basisPoints);
    }

    /**
     * The net price, rounded HalfUp to the minor unit, at which $costMinor
     * leaves the target margin: cost / (1 - margin). With a VAT rate in basis
     * points the gross price of that net price follows (a list including
     * VAT): the margin is worked out on the net price, never on the gross.
     */
    public function priceFromCost(int $costMinor, ?int $vatBp = null): int
    {
        $net = BigInteger::of($costMinor)->multipliedBy(10000)->dividedBy(10000 - (int) $this->marginBp, RoundingMode::HalfUp);

        if ($vatBp === null) {
            return $net->toInt();
        }

        return $net->multipliedBy(10000 + $vatBp)->dividedBy(10000, RoundingMode::HalfUp)->toInt();
    }

    /** Prices typed in a file: no rule, the file says the price. */
    public static function file(): self
    {
        return new self(PriceUpdateKind::File, null, null, null, null);
    }

    /** @param  array<string, mixed>  $rule */
    public static function fromStored(PriceUpdateKind $kind, array $rule): self
    {
        return match ($kind) {
            PriceUpdateKind::Percent => self::percent((int) $rule['percent_bp']),
            PriceUpdateKind::Amount => self::amount((int) $rule['amount_minor']),
            PriceUpdateKind::Index => self::index(BigDecimal::of((string) $rule['index_old']), BigDecimal::of((string) $rule['index_new'])),
            PriceUpdateKind::Margin => self::margin((int) $rule['margin_bp']),
            default => self::file(),
        };
    }

    /** An index figure as typed, with a decimal comma or point; null when it is not a number above zero. */
    public static function parseIndex(string $input): ?BigDecimal
    {
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($input));

        if (! preg_match('/^\d{1,9}(\.\d{1,6})?$/', $value)) {
            return null;
        }

        try {
            $figure = BigDecimal::of($value);
        } catch (MathException) {
            return null;
        }

        return $figure->isGreaterThan(0) ? $figure : null;
    }

    /** @return array<string, int|string> */
    public function toStored(): array
    {
        return match ($this->kind) {
            PriceUpdateKind::Percent => ['percent_bp' => (int) $this->percentBp],
            PriceUpdateKind::Amount => ['amount_minor' => (int) $this->amountMinor],
            PriceUpdateKind::Index => ['index_old' => (string) $this->indexOld, 'index_new' => (string) $this->indexNew],
            PriceUpdateKind::Margin => ['margin_bp' => (int) $this->marginBp],
            default => [],
        };
    }

    /**
     * The new price before the rounding rule, HalfUp to the minor unit. May
     * be negative (a fixed reduction larger than the price); the caller
     * refuses that.
     */
    public function unrounded(int $oldMinor): int
    {
        return match ($this->kind) {
            PriceUpdateKind::Percent => BigInteger::of($oldMinor)->multipliedBy(10000 + (int) $this->percentBp)->dividedBy(10000, RoundingMode::HalfUp)->toInt(),
            PriceUpdateKind::Amount => $oldMinor + (int) $this->amountMinor,
            PriceUpdateKind::Index => BigDecimal::of($oldMinor)->multipliedBy((string) $this->indexNew)->dividedBy((string) $this->indexOld, 0, RoundingMode::HalfUp)->toInt(),
            default => $oldMinor,
        };
    }

    /** The new price after the rounding rule. */
    public function apply(int $oldMinor, PriceRounding $rounding): int
    {
        return $rounding->apply($this->unrounded($oldMinor));
    }
}
