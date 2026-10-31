<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Domain;

enum DocumentType: string
{
    case DailyReport = 'DAILY_REPORT';
    case SupplierInvoice = 'SUPPLIER_INVOICE';
    case Receipt = 'RECEIPT';
    case Sie = 'SIE';
    case BankStatement = 'BANK_STATEMENT';
    case PeppolInvoice = 'PEPPOL_INVOICE';
    case Authority = 'AUTHORITY';
    case Contract = 'CONTRACT';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::DailyReport => __('Dagsrapport (Z-rapport)'),
            self::SupplierInvoice => __('Leverantörsfaktura'),
            self::Receipt => __('Kvitto'),
            self::Sie => __('SIE-fil'),
            self::BankStatement => __('Kontoutdrag'),
            self::PeppolInvoice => __('Peppol-faktura'),
            self::Authority => __('Myndighetspost'),
            self::Contract => __('Avtal'),
            self::Other => __('Övrigt'),
        };
    }
}
