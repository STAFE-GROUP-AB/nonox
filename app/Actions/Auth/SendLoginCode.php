<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Support\Branding\PresentedBrand;
use App\Support\LoginCodeAutofill;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sends a one-time sign-in code. At most one email goes out per cooldown: a
 * still-valid code sent within the cooldown is reused instead of sending a
 * new one, and a lock serialises concurrent requests (double clicks, Enter).
 *
 * Caps keep the form from flooding an inbox: a few codes per address in ten
 * minutes and per day, and a ceiling for the whole service per hour. They
 * count mails actually sent, never requests. When a cap is reached nothing
 * new is sent and the latest code stays valid, so someone who asks for codes
 * in another person's name cannot lock that person out: the owner still has
 * the latest code in their inbox (security review 2026-11-04).
 */
class SendLoginCode
{
    public const int MAX_SENDS_PER_TEN_MINUTES = 5;

    public const int MAX_SENDS_PER_DAY = 20;

    public const int MAX_SENDS_PER_HOUR_GLOBAL = 500;

    public const string GLOBAL_KEY = 'login-code:global';

    public function __invoke(string $email, ?string $ipAddress = null, ?PresentedBrand $brand = null): void
    {
        $email = self::normalise($email);

        Cache::lock('login-code:send:'.sha1($email), 10)->block(5, function () use ($email, $ipAddress, $brand): void {
            if ($this->recentlySent($email)) {
                return;
            }

            if ($this->capReached($email)) {
                return;
            }

            $code = $this->generate();

            DB::transaction(function () use ($email, $code, $ipAddress): void {
                LoginCode::usableFor($email)->update(['consumed_at' => now()]);

                LoginCode::create([
                    'email' => $email,
                    'code_hash' => LoginCode::hash($code),
                    'expires_at' => now()->addMinutes((int) config('auth.login_codes.ttl_minutes')),
                    'ip_address' => $ipAddress,
                ]);
            });

            RateLimiter::hit(self::tenMinuteKey($email), 600);
            RateLimiter::hit(self::dayKey($email), 86400);
            RateLimiter::hit(self::GLOBAL_KEY, 3600);

            LoginCodeAutofill::remember($email, $code);

            Mail::to($email)->send(new LoginCodeMail($code, $brand));
        });
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function tenMinuteKey(string $email): string
    {
        return 'login-code:send:'.self::normalise($email);
    }

    public static function dayKey(string $email): string
    {
        return 'login-code:day:'.self::normalise($email);
    }

    private function capReached(string $email): bool
    {
        if (RateLimiter::tooManyAttempts(self::GLOBAL_KEY, self::MAX_SENDS_PER_HOUR_GLOBAL)) {
            Log::warning('Login: the hourly cap on login code mails was reached.');

            return true;
        }

        return RateLimiter::tooManyAttempts(self::tenMinuteKey($email), self::MAX_SENDS_PER_TEN_MINUTES)
            || RateLimiter::tooManyAttempts(self::dayKey($email), self::MAX_SENDS_PER_DAY);
    }

    private function recentlySent(string $email): bool
    {
        return LoginCode::usableFor($email)
            ->where('created_at', '>', now()->subSeconds((int) config('auth.login_codes.cooldown_seconds')))
            ->exists();
    }

    private function generate(): string
    {
        $length = (int) config('auth.login_codes.length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
