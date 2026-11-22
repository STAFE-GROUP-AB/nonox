<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A cap on e-mail a company sends through NONOX per hour (invoices, quotes,
 * reminders, invitations), so a new account cannot use the service's
 * sending domain for bulk mail. Generous for normal use.
 */
final class MailQuota
{
    /** Per company and hour. */
    public const array LIMITS = ['documents' => 300, 'invitations' => 30];

    /** @throws ValidationException */
    public static function take(Company $company, string $kind, string $field = 'email'): void
    {
        $key = "mail-quota:{$kind}:{$company->id}";

        if (RateLimiter::tooManyAttempts($key, self::LIMITS[$kind])) {
            throw ValidationException::withMessages([$field => __('Företaget har skickat många e-postmeddelanden den senaste timmen. Vänta en stund och försök igen.')]);
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * Counts mails that are sent because of someone else's act and must not
     * be refused, such as the next signers' links and the copies when a
     * signer signs (ADR-046 S1): they use up the hour's quota so that the
     * company's own sends are held back, but the act itself always succeeds.
     */
    public static function count(Company $company, string $kind, int $mails): void
    {
        $key = "mail-quota:{$kind}:{$company->id}";

        for ($i = 0; $i < $mails; $i++) {
            RateLimiter::hit($key, 3600);
        }
    }

    /** How many more mails the company may send this hour (ADR-046 S15: a bulk send waits instead of failing). */
    public static function remaining(Company $company, string $kind): int
    {
        return max(0, RateLimiter::remaining("mail-quota:{$kind}:{$company->id}", self::LIMITS[$kind]));
    }

    /** Seconds until the oldest of this hour's mails stops counting; 0 when nothing is held back. */
    public static function availableIn(Company $company, string $kind): int
    {
        return RateLimiter::availableIn("mail-quota:{$kind}:{$company->id}");
    }
}
