<?php

declare(strict_types=1);

namespace App\Domain\Signing;

/**
 * One step in the audit trail of a link sent for signing (ADR-046). The
 * envelope's own steps (ADR-046 amendment, gap 4) are recorded on the
 * signer row that caused them: CHAIN_STARTED (the chain of an envelope that
 * existed before the chain; its payload names the earlier events),
 * COMPLETED and VOIDED. The signer checks of gap 3 (S3) add CODE_SENT,
 * VERIFIED, VERIFY_FAILED, LOCKED and UNLOCKED, with ids, enums and counts
 * in the payload only, never a code. DEADLINE_CHANGED and REMINDER_SENT (gap 9) are
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
    /** Signer checks (gap 3, S3): a code mailed, a check passed, a failed try, the signer locked out, unlocked by the sender. */
    case CodeSent = 'CODE_SENT';
    case Verified = 'VERIFIED';
    case VerifyFailed = 'VERIFY_FAILED';
    case Locked = 'LOCKED';
    case Unlocked = 'UNLOCKED';
    case DeadlineChanged = 'DEADLINE_CHANGED';
    case ReminderSent = 'REMINDER_SENT';

    /** A step of the signer's own link (the six of ADR-046), not an event of the envelope's chain. */
    public function isSignerStep(): bool
    {
        return in_array($this, [self::Sent, self::Opened, self::Signed, self::Declined, self::Expired, self::Revoked], true);
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
            self::CodeSent => __('En engångskod skickades till undertecknarens e-post'),
            self::Verified => __('Undertecknaren klarade kontrollen'),
            self::VerifyFailed => __('Fel kod angavs'),
            self::Locked => __('Länken låstes efter för många felaktiga försök'),
            self::Unlocked => __('Avsändaren låste upp länken'),
            self::DeadlineChanged => __('Sista dag ändrades'),
            self::ReminderSent => __('Påminnelse skickades'),
        };
    }
}
