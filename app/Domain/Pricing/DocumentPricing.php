<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Decides the price of every article row on an invoice, quote or order when
 * it is saved (ADR-082 P3, §5 and §6), with one call to the resolver for the
 * whole document. A price on a row is a copy: what is decided here is stored
 * on the row with its source and explanation, and a price list changed later
 * never reaches it.
 *
 * Per article row, in order:
 *  1. Marked MANUAL by the editor (the person typed the price): kept as typed.
 *  2. No price given: the resolved price. None found → the row must get a
 *     price typed (an error for the caller).
 *  3. The given price is the resolved one: the resolved source.
 *  4. The row is unchanged from a row already stored on this document (for
 *     the same customer, date and currency), or on the quote or order it was
 *     made from (same article, unit, quantity, price, discount and currency):
 *     it keeps that row's source and explanation. So a saved draft is not
 *     re-priced by saving it again, and quote → order → invoice keeps the
 *     quoted price; re-pricing is only "Uppdatera priser".
 *  5. Filled by the editor's price table (any hint other than MANUAL): the
 *     resolved price, since the server is authoritative over the browser.
 *  6. Otherwise (a price typed in the API, a row whose customer, date or
 *     currency changed, an old row): MANUAL.
 *
 * An agreed discount (a discount item) is folded into the unit price,
 * rounded half up once per unit, and the row's discount field is left for a
 * discount typed on top (decision 8, Lead 2026-11-20): the editor shows the
 * same, so a typed discount gives the same amount in the browser and on the
 * saved row. The explanation keeps folded_discount_bp.
 * TODO(legal-verify): P-L4 — that an invoice showing the folded net price as
 * its unit price, with only the typed discount as a discount, meets
 * mervärdesskattelagen (2023:200) 17 kap. 24 § p. 9 (unit price excl. VAT
 * and reductions not included in the unit price).
 *
 * A price from a list with prices including VAT gives the row the net unit
 * price rounded down (floor(gross × 10000 / (10000 + VAT rate))), so the
 * consumer's total including VAT is never more than the shown price × the
 * quantity, whatever the VAT rounding on the document's sums.
 * TODO(legal-verify): P-L1 — whether the total may instead be up to one öre
 * under the shown price, and how öresavrundning toward a consumer applies.
 */
final class DocumentPricing
{
    public function __construct(private readonly PriceResolver $resolver) {}

    /**
     * @param  array<int, array{article_id: int, unit_id: ?int, quantity: string, price_minor: ?int, discount_bp: ?int, hint: ?string, vat_rate?: int}>  $rows  the article rows, by the caller's index; price and discount null when not given
     * @param  list<RowPriceSnapshot>  $previous  rows already stored on the document or carried from the one it was made from
     * @return array<int, array{unit_price_minor: int, discount_bp: int, source: PriceSource, explanation: array<string, mixed>}|null> null where no price can be found and none was given
     */
    public function price(PriceContext $context, array $rows, array $previous = []): array
    {
        if ($rows === []) {
            return [];
        }

        $requests = [];

        foreach ($rows as $index => $row) {
            $manual = $row['hint'] === PriceSource::Manual->value && $row['price_minor'] !== null;

            $requests[] = new PriceRequest(
                key: "r{$index}",
                articleId: $row['article_id'],
                quantityMilli: self::milli($row['quantity']),
                unitId: $row['unit_id'],
                manualPriceMinor: $manual ? $row['price_minor'] : null,
                manualDiscountBp: $manual ? ($row['discount_bp'] ?? 0) : null,
            );
        }

        $resolved = $this->resolver->resolveMany($context, $requests);
        $kept = [];

        foreach ($previous as $snapshot) {
            $kept[$snapshot->key()] ??= $snapshot;
        }

        $result = [];

        foreach ($rows as $index => $row) {
            $result[$index] = $this->decide($context, $row, $resolved["r{$index}"] ?? new ResolvedPrice("r{$index}", null, 0, $context->currency, null), $kept);
        }

        return $result;
    }

    /**
     * The row a resolved price gives, with a discount typed on top: an agreed
     * discount folded into the unit price, the typed discount as the row's
     * discount, and from a list including VAT the net price rounded down.
     * Null when there is no resolved price.
     *
     * @param  ?int  $vatBp  the row's VAT rate, for a price from a list including VAT
     * @return array{unit_price_minor: int, discount_bp: int, source: PriceSource, explanation: array<string, mixed>}|null
     */
    public static function withDiscount(ResolvedPrice $price, ?int $typedDiscountBp, ?int $vatBp = null): ?array
    {
        if ($price->unitPriceMinor === null || $price->source === null) {
            return null;
        }

        $explanation = self::explanation($price);
        $unit = $price->unitPriceMinor;

        if ($price->includesVat && $price->grossUnitPriceMinor !== null && $vatBp !== null && $price->discountBp === 0 && $price->source !== PriceSource::Manual) {
            $unit = self::consumerNet($price->grossUnitPriceMinor, $vatBp);
            $explanation['net_rounded_down'] = true;
        }

        if ($price->discountBp > 0 && $price->source !== PriceSource::Manual) {
            $unit = PriceCandidate::scale($unit, 10000 - $price->discountBp, 10000);
            $explanation['folded_discount_bp'] = $price->discountBp;
        }

        $discount = $price->source === PriceSource::Manual ? $price->discountBp : ($typedDiscountBp ?? 0);

        if ($price->source !== PriceSource::Manual && $discount > 0) {
            $explanation['manual_discount_bp'] = $discount;
        }

        return ['unit_price_minor' => $unit, 'discount_bp' => $discount, 'source' => $price->source, 'explanation' => $explanation];
    }

    /** The net unit price from a gross one, rounded down so the price including VAT is never more than the gross. */
    public static function consumerNet(int $grossMinor, int $vatBp): int
    {
        return BigRational::of($grossMinor)->multipliedBy(10000)->dividedBy(10000 + $vatBp)->toScale(0, RoundingMode::Floor)->toInt();
    }

    /**
     * What a row stores as its explanation: the resolver's ids and amounts,
     * the candidates that lost and the warnings, as keys and numbers only.
     * The resolver's own folding of a manual discount is redone by
     * withDiscount(), so its keys are not copied.
     *
     * @return array<string, mixed>
     */
    public static function explanation(ResolvedPrice $price): array
    {
        $explanation = $price->explanation;
        unset($explanation['folded_discount_bp'], $explanation['manual_discount_bp']);

        return array_filter([
            ...$explanation,
            'considered' => $price->considered === [] ? null : $price->considered,
            'warnings' => $price->warnings === [] ? null : $price->warnings,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** A quantity as typed ("2.5", "-3") in milli-units, without its sign: a ladder counts how many. */
    public static function milli(string $quantity): int
    {
        return BigDecimal::of(ltrim($quantity, '-'))->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * @param  array{article_id: int, unit_id: ?int, quantity: string, price_minor: ?int, discount_bp: ?int, hint: ?string, vat_rate?: int}  $row
     * @param  array<string, RowPriceSnapshot>  $kept
     * @return array{unit_price_minor: int, discount_bp: int, source: PriceSource, explanation: array<string, mixed>}|null
     */
    private function decide(PriceContext $context, array $row, ResolvedPrice $resolved, array $kept): ?array
    {
        $typed = $row['price_minor'];
        $discount = $row['discount_bp'];

        if ($resolved->source === PriceSource::Manual) {
            return ['unit_price_minor' => (int) $typed, 'discount_bp' => $discount ?? 0, 'source' => PriceSource::Manual, 'explanation' => ['source' => PriceSource::Manual->value]];
        }

        // A discount equal to the agreed one, typed next to the ordinary price, is that agreed discount and not one on top.
        $onTop = $discount !== null && $resolved->discountBp > 0 && $discount === $resolved->discountBp && $typed === $resolved->unitPriceMinor ? null : $discount;
        $candidate = self::withDiscount($resolved, $onTop, $row['vat_rate'] ?? null);

        if ($typed === null) {
            return $candidate;
        }

        // The folded price, or the ordinary price an agreed discount is taken from (an older row, the API), is the resolved one.
        if ($candidate !== null && ($typed === $candidate['unit_price_minor'] || ($resolved->discountBp > 0 && $typed === $resolved->unitPriceMinor))) {
            return $candidate;
        }

        $snapshot = $kept[RowPriceSnapshot::keyOf($context->currency, $row['article_id'], $row['unit_id'], self::milli($row['quantity']), $typed, $discount ?? 0)] ?? null;

        if ($snapshot !== null) {
            return ['unit_price_minor' => $typed, 'discount_bp' => $discount ?? 0, 'source' => $snapshot->source, 'explanation' => $snapshot->explanation];
        }

        $fromTable = $row['hint'] !== null && $row['hint'] !== PriceSource::Manual->value;

        if ($fromTable && $candidate !== null) {
            return $candidate;
        }

        return [
            'unit_price_minor' => $typed,
            'discount_bp' => $discount ?? 0,
            'source' => PriceSource::Manual,
            'explanation' => array_filter(['source' => PriceSource::Manual->value, 'warnings' => $resolved->warnings === [] ? null : $resolved->warnings]),
        ];
    }
}
