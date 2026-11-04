<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Support\Auth\ClientAddressBucket;
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
 * Caps keep the form from flooding an inbox. They count mails handed over
 * for sending (scheduled with defer), never requests: a few per address in
 * ten minutes, a number per address and day from one client address
 * (IPv6 per /64) with a higher ceiling per address from all of them, and, for addresses without an
 * account (open self-registration), a ceiling for the whole service per
 * hour. When a cap is reached nothing new is sent and the latest code stays
 * valid until it expires. That makes it harder for someone asking for codes
 * in another person's name to disturb that person's sign-in, though not
 * impossible: after the latest code has expired the owner may have to wait
 * (security review 2026-11-04).
 *
 * The mail is sent after the response (defer): the form answers in almost
 * the same time whether or not a mail goes out, without waiting for the mail
 * server. It is not queued, so a code never waits behind long jobs.
 */
class SendLoginCode
{
    public const int MAX_SENDS_PER_TEN_MINUTES = 5;

    /** Per address and client address (IPv6 per /64) and day. */
    public const int MAX_SENDS_PER_DAY = 20;

    /** Per address and day, from all client addresses together. */
    public const int MAX_SENDS_PER_DAY_TOTAL = 50;

    /** Per hour for the whole service, for addresses without an account only. */
    public const int MAX_SENDS_PER_HOUR_GLOBAL = 500;

    public const string GLOBAL_KEY = 'login-code:global';

    public function __invoke(string $email, ?string $ipAddress = null, ?PresentedBrand $brand = null): void
    {
        $email = self::normalise($email);

        Cache::lock('login-code:send:'.sha1($email), 10)->block(5, function () use ($email, $ipAddress, $brand): void {
            if ($this->recentlySent($email)) {
                return;
            }

            $hasAccount = User::query()->byEmail($email)->whereNull('anonymised_at')->exists();

            if ($this->capReached($email, $ipAddress, $hasAccount)) {
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
            RateLimiter::hit(self::dayKey($email, $ipAddress), 86400);
            RateLimiter::hit(self::dayTotalKey($email), 86400);

            if (! $hasAccount) {
                RateLimiter::hit(self::GLOBAL_KEY, 3600);
            }

            LoginCodeAutofill::remember($email, $code);

            defer(fn () => Mail::to($email)->send(new LoginCodeMail($code, $brand)));
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

    public static function dayKey(string $email, ?string $ipAddress): string
    {
        return 'login-code:day:'.self::normalise($email).':'.ClientAddressBucket::for($ipAddress);
    }

    public static function dayTotalKey(string $email): string
    {
        return 'login-code:day-total:'.self::normalise($email);
    }

    private function capReached(string $email, ?string $ipAddress, bool $hasAccount): bool
    {
        if (! $hasAccount && RateLimiter::tooManyAttempts(self::GLOBAL_KEY, self::MAX_SENDS_PER_HOUR_GLOBAL)) {
            // Logged once per ten minutes, not once per request.
            if (Cache::add('login-code:global-warned', true, 600)) {
                Log::warning('Login: the hourly cap on login code mails to addresses without an account was reached.');
            }

            return true;
        }

        return RateLimiter::tooManyAttempts(self::tenMinuteKey($email), self::MAX_SENDS_PER_TEN_MINUTES)
            || RateLimiter::tooManyAttempts(self::dayKey($email, $ipAddress), self::MAX_SENDS_PER_DAY)
            || RateLimiter::tooManyAttempts(self::dayTotalKey($email), self::MAX_SENDS_PER_DAY_TOTAL);
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
