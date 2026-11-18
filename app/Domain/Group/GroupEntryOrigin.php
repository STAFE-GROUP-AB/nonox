<?php

declare(strict_types=1);

namespace App\Domain\Group;

/** How an entry came into the group ledger (ADR-081 E25): an accepted suggestion, typed by a person, or a reversal. */
enum GroupEntryOrigin: string
{
    case Suggestion = 'SUGGESTION';
    case Manual = 'MANUAL';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::Suggestion => __('Godkänt förslag'),
            self::Manual => __('Manuell post'),
            self::Reversal => __('Återföring'),
        };
    }
}
