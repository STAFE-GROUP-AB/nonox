<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/**
 * Why a contract is recorded but not counted in MRR. Listed under Källor so
 * nothing is left out silently (ADR-080 §3.1, §4 screen 9).
 */
enum ExclusionReason: string
{
    /** A CRM agreement billed by an avtalsfakturering: that one is counted, so nothing counts twice. */
    case LinkedRecurringInvoice = 'LINKED_RECURRING_INVOICE';
    /** A CRM agreement with a total value needs both a start and an end date to be spread per month. */
    case TotalWithoutDates = 'TOTAL_WITHOUT_DATES';
    /** A ramavtal commits to no amount. */
    case Framework = 'FRAMEWORK';
    case NoValue = 'NO_VALUE';
    /** A CRM account that is not linked to a customer in the customer register. */
    case NoCustomer = 'NO_CUSTOMER';
    /** The CRM module is switched off, so its agreement register is not kept up to date (review 4). */
    case CrmOff = 'CRM_OFF';

    public function label(): string
    {
        return match ($this) {
            self::LinkedRecurringInvoice => __('Faktureras med en avtalsfakturering, som räknas i stället'),
            self::TotalWithoutDates => __('Totalvärde utan både start- och slutdatum'),
            self::Framework => __('Ramavtal utan bestämt belopp'),
            self::NoValue => __('Inget värde'),
            self::NoCustomer => __('Kontot är inte kopplat till en kund'),
            self::CrmOff => __('CRM är avstängt, så avtalsregistret räknas inte'),
        };
    }

    /** True when it is only a note, not something to fix. */
    public function isExpected(): bool
    {
        return $this === self::LinkedRecurringInvoice || $this === self::Framework || $this === self::CrmOff;
    }
}
