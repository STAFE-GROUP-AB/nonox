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
    /** A Stripe subscription whose first payment has not gone through (incomplete, incomplete_expired). */
    case StripeNotStarted = 'STRIPE_NOT_STARTED';
    /** A Stripe price NONOX cannot turn into an amount per period yet (tiered, or without a unit amount). */
    case StripeUnsupportedPrice = 'STRIPE_UNSUPPORTED_PRICE';
    /** The Stripe connection was ended, so its subscriptions are no longer kept up to date (step 2). */
    case StripeDisconnected = 'STRIPE_DISCONNECTED';

    public function label(): string
    {
        return match ($this) {
            self::LinkedRecurringInvoice => __('Faktureras med en avtalsfakturering, som räknas i stället'),
            self::TotalWithoutDates => __('Totalvärde utan både start- och slutdatum'),
            self::Framework => __('Ramavtal utan bestämt belopp'),
            self::NoValue => __('Inget värde'),
            self::NoCustomer => __('Kontot är inte kopplat till en kund'),
            self::CrmOff => __('CRM är avstängt, så avtalsregistret räknas inte'),
            self::StripeNotStarted => __('Första betalningen i Stripe är inte gjord'),
            self::StripeUnsupportedPrice => __('Priset i Stripe har trappsteg eller saknar styckpris och räknas inte än'),
            self::StripeDisconnected => __('Stripe är frånkopplat, så prenumerationen räknas inte'),
        };
    }

    /** True when it is only a note, not something to fix. */
    public function isExpected(): bool
    {
        return in_array($this, [self::LinkedRecurringInvoice, self::Framework, self::CrmOff, self::StripeNotStarted, self::StripeDisconnected], true);
    }

    /**
     * The contract is in force but its source is switched off (the CRM module
     * off, Stripe disconnected): its MRR leaves and returns through the
     * bridge's own row, never as churn or a new customer (re-check B).
     */
    public function isSourceOff(): bool
    {
        return $this === self::CrmOff || $this === self::StripeDisconnected;
    }
}
