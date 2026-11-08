<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders\Domain;

/**
 * The three cases in KtjL 8 § andra och tredje stycket where additional
 * work may, or must, be done without the consumer's instructions (AO-R4).
 * NONOX records the case; it never judges it.
 *
 * TODO(legal-verify): L3 — what "obetydligt" and "lågt i förhållande till
 * priset" mean in numbers. NONOX suggests no percentage.
 */
enum PerformedReason: string
{
    /** The price is insignificant or low compared with the agreed price (8 § andra stycket 1). */
    case LowPrice = 'LOW_PRICE';

    /** Special reasons to assume the consumer wants it done (8 § andra stycket 2). */
    case PresumedWish = 'PRESUMED_WISH';

    /** Cannot be postponed without risk of serious damage (8 § tredje stycket). */
    case UrgentDamage = 'URGENT_DAMAGE';

    public function label(): string
    {
        return match ($this) {
            self::LowPrice => __('Priset är obetydligt eller lågt jämfört med det avtalade priset (8 § andra stycket 1)'),
            self::PresumedWish => __('Särskilda skäl att anta att kunden vill ha arbetet utfört (8 § andra stycket 2)'),
            self::UrgentDamage => __('Akut, för att undvika allvarlig skada (8 § tredje stycket)'),
        };
    }
}
