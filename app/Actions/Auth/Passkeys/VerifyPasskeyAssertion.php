<?php

declare(strict_types=1);

namespace App\Actions\Auth\Passkeys;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Throwable;

/**
 * Checks the browser's answer to a passkey challenge for a given user
 * (ADR-029): the passkey must be one of theirs, the signature must match,
 * the authenticator must have verified the user, and the origin must be
 * NONOX's. Failed attempts are limited per user.
 */
class VerifyPasskeyAssertion
{
    public const int MAX_FAILED = 10;

    /**
     * @param  array<array-key, mixed>  $credential
     */
    public function __invoke(User $user, array $credential): Passkey
    {
        $limiter = 'passkey:failed:'.$user->id;

        if (RateLimiter::tooManyAttempts($limiter, self::MAX_FAILED)) {
            throw PasskeyCeremony::failed(__('För många misslyckade försök. Vänta en stund och försök igen.'));
        }

        try {
            $options = PasskeyCeremony::assertionOptions($user);
            $passkey = app(VerifyPasskey::class)(PasskeyCeremony::credential($credential), $options, $user);
        } catch (ValidationException $exception) {
            RateLimiter::hit($limiter, 600);

            // The package's own messages are English; ours are already Swedish.
            throw array_key_exists('passkey', $exception->errors())
                ? $exception
                : PasskeyCeremony::failed(__('Passkeyn känns inte igen. Den kan ha tagits bort från ditt konto.'));
        } catch (Throwable) {
            RateLimiter::hit($limiter, 600);

            throw PasskeyCeremony::failed();
        }

        RateLimiter::clear($limiter);

        Audit::record('passkey.verified', $passkey, ['name' => $passkey->name], null, $user);

        return $passkey;
    }
}
