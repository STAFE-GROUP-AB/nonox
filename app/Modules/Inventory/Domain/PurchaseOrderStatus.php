<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Where a purchase order is (ADR-071 §2): a draft is still being written; a
 * sent order waits for goods and is ticked off by goods receipts; a
 * cancelled one is kept with its number. A sent order that is changed goes
 * back to DRAFT as its next version and is sent again.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case PartiallyReceived = 'PARTIALLY_RECEIVED';
    case Received = 'RECEIVED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Sent => __('Skickad'),
            self::PartiallyReceived => __('Delvis mottagen'),
            self::Received => __('Mottagen'),
            self::Cancelled => __('Makulerad'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'neutral',
            self::Sent, self::PartiallyReceived => 'accent',
            self::Received => 'success',
            self::Cancelled => 'neutral',
        };
    }

    /** Goods may still arrive on it: sent, and not all of it received. */
    public function awaitsGoods(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyReceived], true);
    }

    /** It still commits the company: what it orders counts as coming (§5). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Sent, self::PartiallyReceived], true);
    }
}
