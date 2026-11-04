<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Modules\Crm\Models\Agreement;

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

    /** The same for an agreement: its terms and its uploaded PDF's hash. */
    public static function forAgreement(Agreement $agreement): string
    {
        $content = [
            'id' => $agreement->id,
            'account_id' => $agreement->account_id,
            'title' => $agreement->title,
            'kind' => $agreement->kind->value,
            'start_date' => $agreement->start_date->toDateString(),
            'end_date' => $agreement->end_date?->toDateString(),
            'notice_months' => $agreement->notice_months,
            'auto_renew' => $agreement->auto_renew,
            'renewal_months' => $agreement->renewal_months,
            'value_ore' => $agreement->value_ore,
            'value_period' => $agreement->value_period->value,
            'body_text' => $agreement->body_text,
            'document_sha256' => $agreement->document_path === null ? null : $agreement->document_sha256,
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
