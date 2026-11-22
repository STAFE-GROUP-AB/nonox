<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;

/**
 * The version of a document's rows that a price floor approval is for
 * (ADR-082 §9): a SHA-256 of what a row sells (article, unit, quantity,
 * price, discount, where the price came from), in row order. A changed row
 * is another version, so an approval never covers rows its approver has not
 * seen. Text, VAT and dimensions are not part of it: they do not move the
 * margin.
 */
final class PriceFloorFingerprint
{
    public static function of(Invoice|SalesDocument $document): string
    {
        $rows = $document->relationLoaded('rows') ? $document->rows : $document->rows()->get();

        $header = $document instanceof Invoice
            ? [$document->customer_id, $document->invoice_date->toDateString(), $document->invoice_currency]
            : [$document->customer_id, $document->document_date->toDateString(), $document->currency];

        return self::fromRows($rows, $header);
    }

    /**
     * @param  iterable<int, InvoiceRow|SalesDocumentRow>  $rows  in row order or sorted here by position
     * @param  list<mixed>  $header  customer, date and currency: another customer, day or currency is another version
     */
    public static function fromRows(iterable $rows, array $header = []): string
    {
        $parts = collect($rows)->sortBy('position')->values()->map(fn ($row): array => [
            $row->article_id,
            (string) $row->unit,
            (string) $row->quantity,
            $row->unit_price_ore->ore,
            $row->discount_bp,
            $row->price_source?->value,
        ])->all();

        return hash('sha256', (string) json_encode([$header, $parts]));
    }
}
