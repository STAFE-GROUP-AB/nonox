<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Where a price is asked for (ADR-082 §5 and §10). A CHANNEL list must be, and a
 * CAMPAIGN list may be, tied to one channel; the resolver gets the channel of the
 * document it prices.
 */
enum PriceChannel: string
{
    case Sales = 'SALES';
    case Portal = 'PORTAL';
    case Pos = 'POS';
    case Recurring = 'RECURRING';
    case WorkOrder = 'WORK_ORDER';

    /** The two choices a campaign has: the till, or every document (ADR-082 P11). */
    public function campaignLabel(): string
    {
        return match ($this) {
            self::Pos => __('Kassan'),
            default => __('Fakturor, offerter och order'),
        };
    }

    /** @return list<self> */
    public static function campaignChoices(): array
    {
        return [self::Pos, self::Sales];
    }

    public function label(): string
    {
        return match ($this) {
            self::Sales => __('Fakturor och offerter'),
            self::Portal => __('Kundportalen'),
            self::Pos => __('Kassan'),
            self::Recurring => __('Återkommande fakturor'),
            self::WorkOrder => __('Arbetsorder'),
        };
    }
}
