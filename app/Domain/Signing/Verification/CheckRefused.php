<?php

declare(strict_types=1);

namespace App\Domain\Signing\Verification;

use RuntimeException;

/**
 * A signer check that did not pass (ADR-046 amendment, gap 3), thrown after
 * the transaction that counted it has committed, so a failed attempt is
 * never rolled back with the error.
 */
final class CheckRefused extends RuntimeException
{
    public const string WRONG = 'WRONG';

    public const string EXPIRED = 'EXPIRED';

    public const string NO_CODE = 'NO_CODE';

    public const string LOCKED = 'LOCKED';

    public const string TOO_MANY_CODES = 'TOO_MANY_CODES';

    public const string NOT_SENT = 'NOT_SENT';

    public const string TEST_COMPANY = 'TEST_COMPANY';

    public function __construct(public readonly string $reason, public readonly int $remaining = 0)
    {
        parent::__construct($reason);
    }

    /** What the signer reads, in Swedish. Never says whether a code exists for someone else. */
    public function text(): string
    {
        return match ($this->reason) {
            self::WRONG => $this->remaining > 0
                ? __('Koden stämmer inte. Försök kvar: :count.', ['count' => $this->remaining])
                : __('Koden stämmer inte.'),
            self::EXPIRED => __('Koden gäller inte längre. Be om en ny kod.'),
            self::NO_CODE => __('Be om en kod först.'),
            self::LOCKED => __('För många felaktiga försök. Kontakta avsändaren, som kan låsa upp länken.'),
            self::TOO_MANY_CODES => __('Du har bett om flera koder den senaste timmen. Vänta en stund och försök igen.'),
            self::NOT_SENT => __('Koden kunde inte skickas just nu. Försök igen om en minut.'),
            self::TEST_COMPANY => __('Det här är ett testföretag i NONOX. Testföretag skickar inga mejl, så ingen kod kan skickas. Be avsändaren skicka med en åtkomstkod i stället.'),
            default => __('Kontrollen gick inte igenom.'),
        };
    }
}
