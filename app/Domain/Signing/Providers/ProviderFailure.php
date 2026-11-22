<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use RuntimeException;

/**
 * Why a provider step failed (ADR-046 S17). The code is short and stable,
 * for the run's row and the audit log; message() is a Swedish sentence for
 * the signer or the settings page. Never carries a token, a code, a
 * signature or anything the provider answered.
 */
final class ProviderFailure extends RuntimeException
{
    public const string UNSAFE_ADDRESS = 'unsafe_address';

    public const string UNREACHABLE = 'unreachable';

    public const string REFUSED = 'refused';

    public const string INVALID_ANSWER = 'invalid_answer';

    public const string UNSUPPORTED = 'unsupported_service';

    public const string NO_CREDENTIAL = 'no_credential';

    public const string UNSUPPORTED_ALGORITHM = 'unsupported_algorithm';

    public const string CERTIFICATE_NOT_VALID = 'certificate_not_valid';

    public const string DECLINED = 'declined_at_provider';

    public const string EXPIRED = 'expired';

    public const string WRONG_BROWSER = 'wrong_browser';

    public const string DOCUMENT_CHANGED = 'document_changed';

    public const string SIGNATURE_INVALID = 'signature_invalid';

    public const string ENGINE = 'engine_failed';

    public const string UNAVAILABLE = 'provider_unavailable';

    public const string SUPERSEDED = 'superseded';

    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct($reason);
    }

    public function message(): string
    {
        return match ($this->reason) {
            self::UNSAFE_ADDRESS => __('Adressen måste gå till en server på internet med https, inte till ett internt nät.'),
            self::UNREACHABLE => __('Tjänsten för underskrift svarade inte. Försök igen om en stund.'),
            self::REFUSED => __('Tjänsten för underskrift tog inte emot begäran.'),
            self::INVALID_ANSWER => __('Tjänsten för underskrift svarade på ett sätt som NONOX inte förstår.'),
            self::UNSUPPORTED => __('Tjänsten stöder inte det som NONOX behöver: inloggning med OAuth 2.0 och underskrift av en kontrollsumma (signHash).'),
            self::NO_CREDENTIAL => __('Du har inget certifikat för underskrift hos tjänsten som kan användas här.'),
            self::UNSUPPORTED_ALGORITHM => __('Certifikatet hos tjänsten använder en nyckeltyp som NONOX inte stöder ännu.'),
            self::CERTIFICATE_NOT_VALID => __('Certifikatet hos tjänsten gäller inte just nu.'),
            self::DECLINED => __('Underskriften avbröts hos tjänsten. Ingenting har skrivits under.'),
            self::EXPIRED, self::WRONG_BROWSER, self::SUPERSEDED => __('Underskriften hos tjänsten gick inte att slutföra. Börja om från dokumentet.'),
            self::DOCUMENT_CHANGED => __('Någon annan har skrivit under dokumentet sedan sidan öppnades. Ladda om sidan, läs dokumentet igen och signera sedan.'),
            self::SIGNATURE_INVALID => __('Underskriften från tjänsten stämde inte med dokumentet och sparades inte.'),
            self::ENGINE => __('Dokumentet kunde inte skrivas under just nu. Försök igen om en stund.'),
            default => __('Underskrift hos en extern tjänst är inte tillgänglig just nu.'),
        };
    }
}
