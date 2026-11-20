<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * What became of one price in a mass price update (ADR-082 §8): planned
 * (ready, unchanged or refused), left out by the user, written, skipped
 * when a scheduled base price had changed in between, or reverted.
 */
enum PriceUpdateLineStatus: string
{
    case Ready = 'READY';
    case Unchanged = 'UNCHANGED';
    case Refused = 'REFUSED';
    case Excluded = 'EXCLUDED';
    case Applied = 'APPLIED';
    case Skipped = 'SKIPPED';
    case Reverted = 'REVERTED';

    public function label(): string
    {
        return match ($this) {
            self::Ready => __('Ändras'),
            self::Unchanged => __('Oförändrat'),
            self::Refused => __('Kan inte ändras'),
            self::Excluded => __('Utesluten'),
            self::Applied => __('Ändrat'),
            self::Skipped => __('Hoppades över'),
            self::Reverted => __('Återställt'),
        };
    }
}
