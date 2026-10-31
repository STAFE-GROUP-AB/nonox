<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Local convenience from ADR-000: the sign-in code is filled in and verified
 * automatically, and the email field is prefilled. Requires APP_ENV=local and
 * OTP_AUTOFILL=true; it is off everywhere else regardless of configuration.
 */
final class LoginCodeAutofill
{
    public static function enabled(): bool
    {
        // Also requires a local address, so a server deployed with the example .env never fills in codes for anyone.
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost');

        return app()->environment('local') && config('auth.login_codes.autofill') === true && $local;
    }

    public static function remember(string $email, string $code): void
    {
        if (! self::enabled()) {
            return;
        }

        Cache::put(self::key($email), $code, now()->addMinutes((int) config('auth.login_codes.ttl_minutes')));
    }

    public static function code(string $email): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $code = Cache::get(self::key($email));

        return is_string($code) ? $code : null;
    }

    public static function email(): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $email = config('app.admin_email');

        if (is_string($email) && $email !== '') {
            return $email;
        }

        return User::query()->orderBy('id')->value('email');
    }

    private static function key(string $email): string
    {
        return 'login-code:autofill:'.sha1($email);
    }
}
