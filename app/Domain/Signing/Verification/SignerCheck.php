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
    /** A pluggable provider (gap 12, S17). Not offered yet. */
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

    public function required(): bool
    {
        return $this !== self::None;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => __('Ingen kontroll, bara länken'),
            self::EmailCode => __('Kod till e-posten'),
            self::AccessCode => __('Åtkomstkod som du lämnar själv'),
            self::Provider => __('Extern tjänst'),
        };
    }

    /** How the certificate names a passed check. */
    public function evidence(): string
    {
        return match ($this) {
            self::None => __('Ingen kontroll utöver länken'),
            self::EmailCode => __('Engångskod till e-postadressen'),
            self::AccessCode => __('Åtkomstkod från avsändaren'),
            self::Provider => __('Extern tjänst'),
        };
    }
}
