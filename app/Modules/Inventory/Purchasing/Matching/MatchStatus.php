<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Matching;

/** Where a row of the three-way comparison stands (ADR-081 E11). */
enum MatchStatus: string
{
    case Within = 'WITHIN';
    case Outside = 'OUTSIDE';
    case Overridden = 'OVERRIDDEN';
    case Unmatched = 'UNMATCHED';

    public function label(): string
    {
        return match ($this) {
            self::Within => __('Inom toleransen'),
            self::Outside => __('Utanför toleransen'),
            self::Overridden => __('Godkänd avvikelse'),
            self::Unmatched => __('Utan orderrad'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Within => 'success',
            self::Outside, self::Unmatched => 'warning',
            self::Overridden => 'neutral',
        };
    }

    /** Waits for a person: an allocation, an override with a reason, or a new comparison. */
    public function isOpen(): bool
    {
        return $this === self::Outside || $this === self::Unmatched;
    }
}
