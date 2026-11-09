<?php

declare(strict_types=1);

namespace App\Modules\Saas\Domain;

/** Where a contract version comes from (ADR-080 §3.1). */
enum SourceType: string
{
    case RecurringInvoice = 'RECURRING_INVOICE';
    case CrmAgreement = 'CRM_AGREEMENT';
    case StripeSubscription = 'STRIPE_SUBSCRIPTION';

    public function label(): string
    {
        return match ($this) {
            self::RecurringInvoice => __('Avtalsfakturering'),
            self::CrmAgreement => __('Avtalsregistret'),
            self::StripeSubscription => __('Stripe-prenumeration'),
        };
    }
}
