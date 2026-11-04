<?php

declare(strict_types=1);

namespace App\Modules\Crm\Support;

use RuntimeException;

/**
 * A signing link that cannot be used (ADR-046), with why, so the public
 * page can say it plainly in Swedish.
 */
final class SigningUnavailable extends RuntimeException
{
    public const string SIGNED = 'signed';

    public const string DECLINED = 'declined';

    public const string REVOKED = 'revoked';

    public const string EXPIRED = 'expired';

    public const string CHANGED = 'changed';

    public const string GONE = 'gone';

    public function __construct(public readonly string $reason, public readonly ?int $requestId = null)
    {
        parent::__construct("Signing link unavailable: {$reason}");
    }

    public static function message(string $reason): string
    {
        return match ($reason) {
            self::SIGNED => __('Dokumentet är redan signerat. Tack! En kopia har skickats till din e-post.'),
            self::DECLINED => __('Du har avböjt dokumentet. Kontakta avsändaren om du har ändrat dig.'),
            self::REVOKED => __('Avsändaren har återkallat länken. Kontakta avsändaren om du behöver en ny.'),
            self::EXPIRED => __('Länken har gått ut. Kontakta avsändaren om du vill ha en ny.'),
            self::CHANGED => __('Dokumentet har ändrats sedan länken skickades och kan inte signeras här. Avsändaren behöver skicka en ny länk.'),
            default => __('Länken gäller inte. Kontrollera att du har kopierat hela adressen, eller kontakta avsändaren.'),
        };
    }
}
