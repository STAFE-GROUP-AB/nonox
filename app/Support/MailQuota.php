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
}
