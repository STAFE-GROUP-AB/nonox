<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/** A mailing's life (ADR-048): written, scheduled, on its way in batches, done, or stopped. */
enum MailingStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Sending = 'SENDING';
    case Sent = 'SENT';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Scheduled => __('Schemalagt'),
            self::Sending => __('Skickas'),
            self::Sent => __('Skickat'),
            self::Cancelled => __('Avbrutet'),
        };
    }
}
