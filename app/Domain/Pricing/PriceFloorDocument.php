<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\Invoice;
use App\Models\SalesDocument;

/** What a price floor approval is for: an invoice, a quote or an order. */
enum PriceFloorDocument: string
{
    case Invoice = 'INVOICE';
    case Quote = 'QUOTE';
    case Order = 'ORDER';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('fakturan'),
            self::Quote => __('offerten'),
            self::Order => __('ordern'),
        };
    }

    public static function of(Invoice|SalesDocument $document): self
    {
        return match (true) {
            $document instanceof Invoice => self::Invoice,
            $document->isQuote() => self::Quote,
            default => self::Order,
        };
    }

    /** The document in the current company; another company's id is not found. */
    public function find(string $id): Invoice|SalesDocument
    {
        return $this === self::Invoice
            ? Invoice::query()->whereKey($id)->firstOrFail()
            : SalesDocument::query()->whereKey($id)->where('kind', $this === self::Quote ? SalesDocument::QUOTE : SalesDocument::ORDER)->firstOrFail();
    }
}
