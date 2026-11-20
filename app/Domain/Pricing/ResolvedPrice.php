<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The price the resolver proposes for one row (ADR-082 §5).
 *
 * - $unitPriceMinor: per the row's unit, excluding VAT, in the document's
 *   currency (öre for SEK); null when no price could be found and the user
 *   must type one ($warnings then says why).
 * - $discountBp: the row discount. A discount item gives the ordinary price
 *   as the unit price and its percent here; a net price gives 0.
 * - $includesVat: the winning list was priced including VAT; the gross price
 *   it gave is $grossUnitPriceMinor and the unit price is derived from it.
 * - $explanation: ids, enums, tiers and minor amounts only, ready for the
 *   row's price_explanation column; never names or free text.
 * - $considered: the candidates that lost or were skipped, each with a
 *   reason key, for the "Varför detta pris?" popover.
 * - $warnings: keys (self::WARNING_*), rendered to text where they are shown.
 */
final readonly class ResolvedPrice
{
    public const string WARNING_CONVERTED = 'converted_currency';

    public const string WARNING_NO_PRICE = 'no_price';

    public const string WARNING_NO_RATE = 'no_exchange_rate';

    public const string WARNING_UNIT = 'unit_not_convertible';

    public const string WARNING_UNKNOWN_ARTICLE = 'unknown_article';

    /**
     * @param  array<string, int|string|bool|null>  $explanation
     * @param  list<array<string, int|string|bool|null>>  $considered
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $key,
        public ?int $unitPriceMinor,
        public int $discountBp,
        public string $currency,
        public ?PriceSource $source,
        public bool $includesVat = false,
        public ?int $grossUnitPriceMinor = null,
        public bool $manualDiscount = false,
        public array $explanation = [],
        public array $considered = [],
        public array $warnings = [],
    ) {}

    public function hasPrice(): bool
    {
        return $this->unitPriceMinor !== null;
    }

    public function hasWarning(string $warning): bool
    {
        return in_array($warning, $this->warnings, true);
    }
}
