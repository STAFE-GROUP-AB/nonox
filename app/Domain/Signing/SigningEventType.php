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
 * the first signer who opens the expired link). The consent to electronic
 * records (gap 7, S4) adds CONSENTED and CONSENT_REFUSED.
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
    /** Fields (gap 2, S6): what a signer filled in (field ids and value hashes, never values), and ids posted for fields that were not theirs. */
    case FieldsFilled = 'FIELDS_FILLED';
    case FieldRejected = 'FIELD_REJECTED';
    /** Consent to electronic records (gap 7, S4): accepted (version and the text's SHA-256), or refused (version only). */
    case Consented = 'CONSENTED';
    case ConsentRefused = 'CONSENT_REFUSED';

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
            self::FieldsFilled => __('Undertecknaren fyllde i sina fält'),
            self::FieldRejected => __('Värden för fält som hör till någon annan togs inte emot'),
            self::Consented => __('Undertecknaren godkände att handlingen skrivs under elektroniskt'),
            self::ConsentRefused => __('Undertecknaren vill inte skriva under elektroniskt'),
        };
    }
}
