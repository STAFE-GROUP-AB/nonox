<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/**
 * Remarks the server attaches to an entry when it is received. They are part
 * of the hashed entry; nothing about the event itself is ever rewritten.
 */
enum EntryFlag: string
{
    /** The device's clock differed from the server's by more than the setting. */
    case ClockSkew = 'CLOCK_SKEW';
    /** Sent from the kiosk's offline queue more than 15 minutes after it happened. */
    case Late = 'LATE';
    /** A sequence number was skipped: an event from the device may be missing. */
    case SequenceGap = 'SEQUENCE_GAP';
    /** The device's own hash chain did not continue from the last accepted event. */
    case ClientChainBreak = 'CLIENT_CHAIN_BREAK';

    public function label(): string
    {
        return match ($this) {
            self::ClockSkew => __('Enhetens klocka gick fel'),
            self::Late => __('Skickad i efterhand'),
            self::SequenceGap => __('En händelse från enheten kan saknas'),
            self::ClientChainBreak => __('Enhetens kedja bröts'),
        };
    }
}
