<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * How an intra-group invoice delivery went, as the sender sees it (ADR-081
 * E27). The sender learns nothing beyond this: not why a delivery was
 * refused, and nothing of how the receiver handles a delivered invoice.
 */
enum IntercompanyDeliveryStatus: string
{
    case Delivered = 'DELIVERED';
    case Refused = 'REFUSED';

    public function label(): string
    {
        return match ($this) {
            self::Delivered => __('Levererad'),
            self::Refused => __('Inte levererad'),
        };
    }
}
