<?php

declare(strict_types=1);

namespace App\Domain\Signing\Verification;

/**
 * How a signer is checked before the document is shown (ADR-046 amendment
 * 2026-11-18, gap 3, package S3). The link alone proves control of the
 * mailbox; an e-mail code protects against a forwarded or leaked link; an
 * access code is given by the sender another way. None of them changes the
 * level: a signature in NONOX stays a simple electronic signature.
 */
enum SignerCheck: string
{
    case None = 'NONE';
    case EmailCode = 'EMAIL_CODE';
    case AccessCode = 'ACCESS_CODE';
    /**
     * Signing at an external provider (gap 12, S17): the signer identifies themselves and signs there with their own
     * certificate. Not a check before the document (the document is shown after the link and the consent, as with
     * NONE); the signature itself is the provider's. Chosen per signer with the provider's id (SendForSigning).
     */
    case Provider = 'PROVIDER';

    /**
     * The checks a sender can choose today.
     *
     * @return list<self>
     */
    public static function choosable(): array
    {
        return [self::None, self::EmailCode, self::AccessCode];
    }

    /** Whether a check must pass before the document is shown (an e-mail code or an access code). */
    public function required(): bool
    {
        return $this === self::EmailCode || $this === self::AccessCode;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => __('Ingen kontroll, bara länken'),
            self::EmailCode => __('Kod till e-posten'),
            self::AccessCode => __('Åtkomstkod som du lämnar själv'),
            self::Provider => __('Underskrift hos extern tjänst'),
        };
    }

    /** How the certificate names a passed check. */
    public function evidence(): string
    {
        return match ($this) {
            self::None => __('Ingen kontroll utöver länken'),
            self::EmailCode => __('Engångskod till e-postadressen'),
            self::AccessCode => __('Åtkomstkod från avsändaren'),
            self::Provider => __('Underskrift hos extern tjänst för underskrift'),
        };
    }
}
