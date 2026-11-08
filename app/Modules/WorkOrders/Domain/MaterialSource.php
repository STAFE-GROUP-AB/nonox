<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * Where material on a work order comes from (ADR-074 §3).
 */
enum MaterialSource: string
{
    /** Issued from stock as a WORK_ORDER_ISSUE movement (Lager on). */
    case Stock = 'STOCK';

    /** A row of an approved supplier invoice, billed on to the customer once. */
    case SupplierInvoiceRow = 'SUPPLIER_INVOICE_ROW';

    /** Typed by hand. */
    case Free = 'FREE';

    public function label(): string
    {
        return match ($this) {
            self::Stock => __('Från lager'),
            self::SupplierInvoiceRow => __('Från leverantörsfaktura'),
            self::Free => __('Övrigt material'),
        };
    }
}
