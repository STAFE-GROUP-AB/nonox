<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The life of a customer price agreement (ADR-082 §4). Only an ACTIVE
 * agreement prices rows; a change is a new version that supersedes the old.
 */
enum PriceAgreementStatus: string
{
    case Draft = 'DRAFT';
    case AwaitingSignature = 'AWAITING_SIGNATURE';
    case Active = 'ACTIVE';
    case Ended = 'ENDED';
    case Terminated = 'TERMINATED';
    case Superseded = 'SUPERSEDED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::AwaitingSignature => __('Väntar på underskrift'),
            self::Active => __('Aktivt'),
            self::Ended => __('Upphört'),
            self::Terminated => __('Uppsagt'),
            self::Superseded => __('Ersatt'),
        };
    }
}
