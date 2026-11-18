<?php

declare(strict_types=1);

namespace App\Domain\Signing;

/**
 * One step in the audit trail of a link sent for signing (ADR-046). The
 * envelope's own steps (ADR-046 amendment, gap 4) are recorded on the
 * signer row that caused them: CHAIN_STARTED (the chain of an envelope that
 * existed before the chain; its payload names the earlier events),
 * COMPLETED and VOIDED. DEADLINE_CHANGED and REMINDER_SENT (gap 9) are
 * recorded on a still open signer's row; EXPIRED, once an envelope passes its
 * deadline, is recorded on one open signer's row by signing:remind (or by
 * the first signer who opens the expired link).
 */
enum SigningEventType: string
{
    case Sent = 'SENT';
    case Opened = 'OPENED';
    case Signed = 'SIGNED';
    case Declined = 'DECLINED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
    case ChainStarted = 'CHAIN_STARTED';
    case Completed = 'COMPLETED';
    case Voided = 'VOIDED';
    case DeadlineChanged = 'DEADLINE_CHANGED';
    case ReminderSent = 'REMINDER_SENT';

    /** A step of the signer's own link (the six of ADR-046), not an event of the envelope's chain. */
    public function isSignerStep(): bool
    {
        return ! in_array($this, [self::ChainStarted, self::Completed, self::Voided, self::DeadlineChanged, self::ReminderSent], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Sent => __('Skickad'),
            self::Opened => __('Länken hämtades första gången'),
            self::Signed => __('Signerad'),
            self::Declined => __('Avböjd'),
            self::Expired => __('Gick ut'),
            self::Revoked => __('Återkallad'),
            self::ChainStarted => __('Händelsekedjan startade'),
            self::Completed => __('Signeringen är klar'),
            self::Voided => __('Signeringen avbröts'),
            self::DeadlineChanged => __('Sista dag ändrades'),
            self::ReminderSent => __('Påminnelse skickades'),
        };
    }
}
