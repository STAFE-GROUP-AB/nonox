<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Domain\Auth\PasskeyRequirement;
use App\Models\User;
use App\Support\LoginCodeAutofill;

/**
 * The passkey step of a signed-in session (ADR-029): whether this session was
 * confirmed with a passkey, and whether it has to be.
 */
final class PasskeySession
{
    /** Set when the session was confirmed with a passkey (assertion or registration with user verification). */
    public const string VERIFIED = 'auth.passkey_verified_at';

    /** Cached "not needed" answer for users who need no passkey, so the rule is not evaluated on every request. */
    private const string NOT_NEEDED = 'auth.passkey_not_needed_until';

    private const int RECHECK_SECONDS = 60;

    public static function markVerified(): void
    {
        session()->put(self::VERIFIED, time());
        session()->forget(self::NOT_NEEDED);
    }

    public static function isVerified(): bool
    {
        return is_int(session()->get(self::VERIFIED));
    }

    /**
     * Whether this session must pass the passkey step before it may be used.
     * The answer "no" is reused for a minute: a role given meanwhile takes
     * effect on a request at most a minute later.
     */
    public static function needsStep(User $user): bool
    {
        if (self::isVerified() || self::skippedLocally()) {
            return false;
        }

        $until = session()->get(self::NOT_NEEDED);

        if (is_int($until) && $until > time()) {
            return false;
        }

        if (PasskeyRequirement::mustVerify($user)) {
            return true;
        }

        session()->put(self::NOT_NEEDED, time() + self::RECHECK_SECONDS);

        return false;
    }

    /**
     * Local development only: WebAuthn needs a secure origin and
     * http://*.test is not one. Needs APP_ENV=local, a local APP_URL and
     * PASSKEYS_SKIP_LOCALLY (or OTP_AUTOFILL), like the code autofill.
     */
    public static function skippedLocally(): bool
    {
        return app()->environment('local')
            && config('auth.passkeys.skip_locally') === true
            && LoginCodeAutofill::localAddress();
    }
}
