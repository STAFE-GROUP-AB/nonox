<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Domain;

/**
 * Where an invoice sent through Peppol is: waiting in NONOX's queue, handed
 * to the access point (SENT), confirmed received by the buyer's access point
 * (DELIVERED), or given up (FAILED, may be sent again).
 */
enum TransmissionStatus: string
{
    case Queued = 'QUEUED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('I kö'),
            self::Sent => __('Skickad till Peppol'),
            self::Delivered => __('Levererad'),
            self::Failed => __('Misslyckades'),
        };
    }

    /** Whether the invoice is on its way or there: a new sending would be a duplicate. */
    public function isActive(): bool
    {
        return $this !== self::Failed;
    }
}
