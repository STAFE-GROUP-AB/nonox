<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A cap per company and hour on work that is heavy for the server or calls
 * an outside service (SIE analysis, VIES, company lookup, archive packages, bank files,
 * generated PDFs and report files), so one account cannot slow the service
 * down for everybody. Generous for normal use; see MailQuota for e-mail.
 */
final class WorkQuota
{
    /** Per company and hour. */
    public const array LIMITS = ['sie_upload' => 30, 'register_upload' => 30, 'vies' => 60, 'archive' => 10, 'bank_file' => 30, 'inbox_mail' => 500, 'pdf' => 300, 'company_lookup' => 300, 'crm_mail' => 500];

    /** @throws ValidationException */
    public static function take(Company $company, string $kind, string $field): void
    {
        if (! self::attempt($company, $kind)) {
            throw ValidationException::withMessages([$field => self::message()]);
        }
    }

    /**
     * Takes one unit of the quota and says whether there was one left. For
     * downloads, where there is no form to show a validation error on.
     */
    public static function attempt(Company $company, string $kind): bool
    {
        $key = "work-quota:{$kind}:{$company->id}";

        if (RateLimiter::tooManyAttempts($key, self::LIMITS[$kind])) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    public static function message(): string
    {
        return __('Det här har gjorts många gånger den senaste timmen. Vänta en stund och försök igen.');
    }
}
