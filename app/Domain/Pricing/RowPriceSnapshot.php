<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\InvoiceRow;
use App\Models\SalesDocumentRow;

/**
 * A priced row already stored (ADR-082 §6): what it was priced at and why.
 * A row saved again unchanged, or carried from a quote to an order and an
 * invoice, keeps this price and explanation instead of being re-priced.
 * Prices are in the document's currency (an invoice in another currency:
 * the foreign price as written), and the currency is part of what must be
 * unchanged: a row of a document whose currency changed is not kept.
 */
final readonly class RowPriceSnapshot
{
    /** @param  array<string, mixed>  $explanation */
    public function __construct(
        public string $currency,
        public int $articleId,
        public ?int $unitId,
        public int $quantityMilli,
        public int $unitPriceMinor,
        public int $discountBp,
        public PriceSource $source,
        public array $explanation,
    ) {}

    /**
     * The priced article rows among the given ones; rows without an
     * article or a source have nothing to keep.
     *
     * @param  iterable<InvoiceRow|SalesDocumentRow>  $rows
     * @param  string  $currency  the currency of the document the rows belong to
     * @return list<self>
     */
    public static function of(iterable $rows, string $currency): array
    {
        $snapshots = [];

        foreach ($rows as $row) {
            if ($row->article_id === null || ! $row->price_source instanceof PriceSource) {
                continue;
            }

            $foreign = $row->getAttribute('foreign_unit_price_minor');

            $snapshots[] = new self(
                currency: $currency,
                articleId: $row->article_id,
                unitId: $row->unit_id,
                quantityMilli: DocumentPricing::milli($row->quantity),
                unitPriceMinor: is_int($foreign) ? $foreign : $row->unit_price_ore->ore,
                discountBp: $row->discount_bp,
                source: $row->price_source,
                explanation: $row->price_explanation ?? ['source' => $row->price_source->value],
            );
        }

        return $snapshots;
    }

    public function key(): string
    {
        return self::keyOf($this->currency, $this->articleId, $this->unitId, $this->quantityMilli, $this->unitPriceMinor, $this->discountBp);
    }

    public static function keyOf(string $currency, int $articleId, ?int $unitId, int $quantityMilli, int $unitPriceMinor, int $discountBp): string
    {
        return implode('|', [$currency, $articleId, $unitId ?? '-', $quantityMilli, $unitPriceMinor, $discountBp]);
    }
}
