<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A cap per company and hour on work that is heavy for the server or calls
 * an outside service (SIE analysis, VIES, archive packages, bank files), so
 * one account cannot slow the service down for everybody. Generous for
 * normal use; see MailQuota for e-mail.
 */
final class WorkQuota
{
    /** Per company and hour. */
    public const array LIMITS = ['sie_upload' => 30, 'vies' => 60, 'archive' => 10, 'bank_file' => 30, 'inbox_mail' => 500];

    /** @throws ValidationException */
    public static function take(Company $company, string $kind, string $field): void
    {
        $key = "work-quota:{$kind}:{$company->id}";

        if (RateLimiter::tooManyAttempts($key, self::LIMITS[$kind])) {
            throw ValidationException::withMessages([$field => __('Det här har gjorts många gånger den senaste timmen. Vänta en stund och försök igen.')]);
        }

        RateLimiter::hit($key, 3600);
    }
}
