<?php

declare(strict_types=1);

namespace App\Domain\Group;

/**
 * What an entry in the group ledger is (ADR-060, addendum 2026-11-07, 2.4):
 * an elimination of an intra-group item, another group adjustment, or the
 * reversal of an earlier entry. A mistake is never changed: it is reversed by
 * a new entry, as a posted voucher is corrected.
 */
enum GroupEntryKind: string
{
    case Elimination = 'ELIMINATION';
    case Adjustment = 'ADJUSTMENT';
    case Reversal = 'REVERSAL';

    public function label(): string
    {
        return match ($this) {
            self::Elimination => __('Eliminering'),
            self::Adjustment => __('Justering'),
            self::Reversal => __('Återföring'),
        };
    }
}
