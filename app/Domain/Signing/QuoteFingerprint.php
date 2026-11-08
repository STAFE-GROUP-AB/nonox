<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;

/**
 * A SHA-256 over what a quote says (ADR-046): customer, dates, references,
 * message, totals and every row. Taken when the quote is sent for signing
 * and again when it is signed: a quote changed in between cannot be signed,
 * so the order made from it is always what the signer saw.
 */
final class QuoteFingerprint
{
    public static function for(SalesDocument $quote): string
    {
        $quote->loadMissing('rows');

        $content = [
            'id' => $quote->id,
            'number' => $quote->number,
            'customer_id' => $quote->customer_id,
            'document_date' => $quote->document_date->toDateString(),
            'until_date' => $quote->until_date?->toDateString(),
            'your_reference' => $quote->your_reference,
            'our_reference' => $quote->our_reference,
            'notes' => $quote->notes,
            'net_ore' => $quote->net_ore->ore,
            'vat_ore' => $quote->vat_ore->ore,
            'rounding_ore' => $quote->rounding_ore->ore,
            'total_ore' => $quote->total_ore->ore,
            'rows' => $quote->rows->map(fn (SalesDocumentRow $row): array => [
                $row->article_id,
                $row->description,
                $row->quantity,
                $row->unit,
                $row->unit_price_ore->ore,
                $row->discount_bp,
                $row->vat_rate,
                $row->account_id,
                $row->net_ore->ore,
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
