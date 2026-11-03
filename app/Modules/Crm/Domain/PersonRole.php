<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/** A person's role at an account (ADR-043). */
enum PersonRole: string
{
    case DecisionMaker = 'DECISION_MAKER';
    case Ambassador = 'AMBASSADOR';
    case Influencer = 'INFLUENCER';
    case User = 'USER';
    case InvoiceRecipient = 'INVOICE_RECIPIENT';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::DecisionMaker => __('Beslutsfattare'),
            self::Ambassador => __('Ambassadör'),
            self::Influencer => __('Påverkare'),
            self::User => __('Användare'),
            self::InvoiceRecipient => __('Fakturamottagare'),
            self::Other => __('Övrig'),
        };
    }
}
