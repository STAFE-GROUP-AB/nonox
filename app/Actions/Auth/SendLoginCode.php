<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Support\LoginCodeAutofill;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a one-time sign-in code. At most one email goes out per cooldown: a
 * still-valid code sent within the cooldown is reused instead of sending a
 * new one, and a lock serialises concurrent requests (double clicks, Enter).
 */
class SendLoginCode
{
    public function __invoke(string $email, ?string $ipAddress = null): void
    {
        $email = self::normalise($email);

        Cache::lock('login-code:send:'.sha1($email), 10)->block(5, function () use ($email, $ipAddress): void {
            if ($this->recentlySent($email)) {
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

            LoginCodeAutofill::remember($email, $code);

            Mail::to($email)->send(new LoginCodeMail($code));
        });
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
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
