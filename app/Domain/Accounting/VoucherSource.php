<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

/**
 * Where a voucher comes from (AGENTS.md §6.4).
 */
enum VoucherSource: string
{
    case Manual = 'MANUAL';
    case Invoice = 'INVOICE';
    case Payment = 'PAYMENT';
    case SupplierInvoice = 'SUPPLIER_INVOICE';
    case Vat = 'VAT';
    case Payroll = 'PAYROLL';
    case ImportSie = 'IMPORT_SIE';
    case ImportFortnox = 'IMPORT_FORTNOX';
    case Closing = 'CLOSING';
    case Correction = 'CORRECTION';
}
