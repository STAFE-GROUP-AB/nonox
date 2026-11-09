<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Support\Auth\ClientAddressBucket;
use App\Support\Branding\PresentedBrand;
use App\Support\Demo\DemoMode;
use App\Support\LoginCodeAutofill;
use Closure;
use Illuminate\Contracts\Mail\Mailable;
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
 * for sending, never requests: a few per address in
 * ten minutes, a number per address and day from one client address
 * (IPv6 per /64) with a higher ceiling per address from all of them, and, for addresses without an
 * account (open self-registration), a ceiling for the whole service per
 * hour. When a cap is reached nothing new is sent and the latest code stays
 * valid until it expires. That makes it harder for someone asking for codes
 * in another person's name to disturb that person's sign-in, though not
 * impossible: after the latest code has expired the owner may have to wait
 * (security review 2026-11-04).
 *
 * The mail is sent during the request, not queued, so a code never waits
 * behind long jobs. It was sent after the response (defer) for a while, but
 * on production PHP-FPM the worker died after the response and no mail went
 * out (2026-11-04), so it is synchronous again until that is understood.
 *
 * A customer portal code (audience PORTAL, ADR-075) is counted apart from
 * the web sign-in's, is only sent to an address with portal access (the
 * caller decides), and is sent from a queued job (SendPortalLoginCode) so the
 * portal's response time never tells whether an address has access.
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

    /**
     * @param  ?Closure(string): Mailable  $mail  the mail for a portal code (the code in, the mail out); the web sign-in's otherwise
     */
    public function __invoke(string $email, ?string $ipAddress = null, ?PresentedBrand $brand = null, string $audience = LoginCode::WEB, ?Closure $mail = null): void
    {
        $email = self::normalise($email);
        // The portal's caps and lock are counted on their own keys, so neither sign-in can use up the other's.
        $key = $audience === LoginCode::WEB ? $email : strtolower($audience).':'.$email;

        // A demo guest's placeholder address (ADR-077) is not an inbox; nothing is sent to it.
        if (str_ends_with($email, '@'.DemoMode::GUEST_DOMAIN)) {
            return;
        }

        Cache::lock('login-code:send:'.sha1($key), 10)->block(5, function () use ($email, $key, $ipAddress, $brand, $audience, $mail): void {
            if ($this->recentlySent($email, $audience)) {
                return;
            }

            // A portal code only goes to an address the caller found a portal contact for.
            $hasAccount = $audience !== LoginCode::WEB || User::query()->byEmail($email)->whereNull('anonymised_at')->exists();

            if ($this->capReached($key, $ipAddress, $hasAccount)) {
                return;
            }

            $code = $this->generate();

            DB::transaction(function () use ($email, $code, $ipAddress, $audience): void {
                LoginCode::usableFor($email, $audience)->update(['consumed_at' => now()]);

                LoginCode::create([
                    'email' => $email,
                    'audience' => $audience,
                    'code_hash' => LoginCode::hash($code),
                    'expires_at' => now()->addMinutes((int) config('auth.login_codes.ttl_minutes')),
                    'ip_address' => $ipAddress,
                ]);
            });

            RateLimiter::hit(self::tenMinuteKey($key), 600);
            RateLimiter::hit(self::dayKey($key, $ipAddress), 86400);
            RateLimiter::hit(self::dayTotalKey($key), 86400);

            if (! $hasAccount) {
                RateLimiter::hit(self::GLOBAL_KEY, 3600);
            }

            if ($audience === LoginCode::WEB) {
                LoginCodeAutofill::remember($email, $code);
            }

            Mail::to($email)->send($mail === null ? new LoginCodeMail($code, $brand) : $mail($code));
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

    private function recentlySent(string $email, string $audience): bool
    {
        return LoginCode::usableFor($email, $audience)
            ->where('created_at', '>', now()->subSeconds((int) config('auth.login_codes.cooldown_seconds')))
            ->exists();
    }

    private function generate(): string
    {
        $length = (int) config('auth.login_codes.length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
