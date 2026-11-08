<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * An ÄTA's way to a decision (ADR-074 §4):
 * DRAFT → SENT → APPROVED | DECLINED | EXPIRED | WITHDRAWN,
 * DRAFT → APPROVED_ORALLY (weaker evidence, shown so),
 * DRAFT → PERFORMED_WITHOUT_ANSWER (KtjL 8 § andra och tredje stycket).
 */
enum ChangeOrderStatus: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case Approved = 'APPROVED';
    case ApprovedOrally = 'APPROVED_ORALLY';
    case PerformedWithoutAnswer = 'PERFORMED_WITHOUT_ANSWER';
    case Declined = 'DECLINED';
    case Expired = 'EXPIRED';
    case Withdrawn = 'WITHDRAWN';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Sent => __('Skickat till kunden'),
            self::Approved => __('Godkänt'),
            self::ApprovedOrally => __('Godkänt muntligt'),
            self::PerformedWithoutAnswer => __('Utfört utan kundens besked'),
            self::Declined => __('Avböjt'),
            self::Expired => __('Har gått ut'),
            self::Withdrawn => __('Återkallat'),
        };
    }

    /** Time and material on the ÄTA may go on an invoice ("price before work"). */
    public function allowsInvoicing(): bool
    {
        return in_array($this, [self::Approved, self::ApprovedOrally, self::PerformedWithoutAnswer], true);
    }

    /** Decided: the ÄTA, its PDF and its evidence never change again (AO-R25). */
    public function isDecided(): bool
    {
        return ! in_array($this, [self::Draft, self::Sent], true);
    }
}
