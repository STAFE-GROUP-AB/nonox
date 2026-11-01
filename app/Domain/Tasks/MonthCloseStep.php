<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** The steps of closing a client's month, the columns of Månadsstängning. */
enum MonthCloseStep: string
{
    case Bank = 'BANK';
    case SupplierInvoices = 'SUPPLIER_INVOICES';
    case CustomerInvoices = 'CUSTOMER_INVOICES';
    case Vat = 'VAT';
    case Payroll = 'PAYROLL';
    case Accruals = 'ACCRUALS';
    case Report = 'REPORT';

    public function label(): string
    {
        return match ($this) {
            self::Bank => __('Bank avstämd'),
            self::SupplierInvoices => __('Leverantörsfakturor'),
            self::CustomerInvoices => __('Kundfakturor'),
            self::Vat => __('Moms'),
            self::Payroll => __('Lön och AGI'),
            self::Accruals => __('Periodiseringar'),
            self::Report => __('Rapport skickad'),
        };
    }

    /** What makes the step done, shown as the column's explanation. */
    public function hint(): string
    {
        return match ($this) {
            self::Bank => __('Klar när en bankavstämning finns per månadens sista dag eller senare.'),
            self::SupplierInvoices => __('Klar när inga leverantörsfakturor från månaden väntar på attest.'),
            self::CustomerInvoices => __('Klar när inga kundfakturor ligger kvar som utkast.'),
            self::Vat => __('Klar när momsen för perioden är redovisad.'),
            self::Payroll => __('Klar när arbetsgivardeklarationen för månaden är lämnad.'),
            self::Accruals => __('Klar när månadens periodiseringar är bokförda.'),
            self::Report => __('Bockas av för hand när månadsrapporten är skickad till kunden.'),
        };
    }
}
