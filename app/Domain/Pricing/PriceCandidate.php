<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * One price the resolver found for a row, before it is chosen (ADR-082 §5).
 * The unit price is per the row's unit, excluding VAT, in the document's
 * currency; the discount, when there is one, is a percentage of it.
 *
 * @internal used by PriceBook and PriceResolver only
 */
final readonly class PriceCandidate
{
    public function __construct(
        public PriceSource $source,
        public int $unitPriceMinor,
        public int $discountBp = 0,
        public bool $includesVat = false,
        public ?int $grossMinor = null,
        public ?int $listId = null,
        public ?int $itemId = null,
        public ?int $agreementId = null,
        public ?int $tierMinMilli = null,
        public ?int $baseMinor = null,
        public bool $derived = false,
        public bool $converted = false,
    ) {}

    /** What one unit really costs after the discount, exactly; what "lowest" compares. */
    public function effective(): BigRational
    {
        return BigRational::of($this->unitPriceMinor)->multipliedBy(10000 - $this->discountBp)->dividedBy(10000);
    }

    /** The effective price rounded half up to the minor unit, as the base a discount or derived list starts from. */
    public function effectiveMinor(): int
    {
        return $this->effective()->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public function isLowerThan(self $other): bool
    {
        return $this->effective()->compareTo($other->effective()) < 0;
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * For the considered list: ids, the price and why it lost.
     *
     * @return array<string, int|string|bool|null>
     */
    public function considered(string $reason): array
    {
        return array_filter([
            'source' => $this->source->value,
            'list_id' => $this->listId,
            'item_id' => $this->itemId,
            'agreement_id' => $this->agreementId,
            'unit_price_minor' => $this->unitPriceMinor,
            'discount_bp' => $this->discountBp,
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** round_half_up(minor × numerator / denominator), exactly. */
    public static function scale(int $minor, int $numerator, int $denominator): int
    {
        return BigRational::of($minor)->multipliedBy($numerator)->dividedBy($denominator)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
