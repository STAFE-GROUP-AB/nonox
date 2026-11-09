<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\LoginCode;
use Illuminate\Support\Facades\DB;

/**
 * Checks a one-time code. A code works once, expires, and stops working after
 * too many wrong attempts, even if the right code is entered afterwards.
 * Only a code issued for the same audience counts (ADR-075).
 */
class VerifyLoginCode
{
    public function __invoke(string $email, string $code, string $audience = LoginCode::WEB): bool
    {
        $email = SendLoginCode::normalise($email);
        $code = preg_replace('/\D/', '', $code) ?? '';

        return DB::transaction(function () use ($email, $code, $audience): bool {
            $loginCode = LoginCode::usableFor($email, $audience)->latest('id')->lockForUpdate()->first();

            if ($loginCode === null) {
                return false;
            }

            if ($loginCode->attempts >= (int) config('auth.login_codes.max_attempts')) {
                return false;
            }

            if (! hash_equals($loginCode->code_hash, LoginCode::hash($code))) {
                $loginCode->increment('attempts');

                return false;
            }

            $loginCode->forceFill(['consumed_at' => now()])->save();

            return true;
        });
    }
}
