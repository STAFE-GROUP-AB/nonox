<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

use App\Actions\Auth\Passkeys\PasskeyCeremony;
use App\Actions\Auth\Passkeys\VerifyPasskeyAssertion;
use App\Models\User;
use App\Support\Auth\PasskeySession;
use Illuminate\Validation\ValidationException;

/**
 * A fresh passkey confirmation before giving an app or a script access to a
 * company (ADR-032, building on ADR-029). The browser answers a challenge
 * limited to the user's own passkeys; the server remembers the answer in the
 * session for a few minutes and the Action that grants access consumes it.
 * A signed-in session alone is not enough: whoever has only taken over the
 * mailbox cannot let an app in.
 */
final class PasskeyStepUp
{
    private const string SESSION_KEY = 'connect.step_up';

    /** @return array<array-key, mixed> options for navigator.credentials.get() */
    public static function start(User $user): array
    {
        if (! $user->hasPasskeysEnabled()) {
            throw self::missingPasskey();
        }

        return PasskeyCeremony::startAssertion($user);
    }

    /**
     * @param  array<array-key, mixed>  $credential
     */
    public static function confirm(User $user, array $credential): void
    {
        app(VerifyPasskeyAssertion::class)($user, $credential);

        session()->put(self::SESSION_KEY, ['user_id' => $user->id, 'at' => time()]);
    }

    public static function isFresh(User $user): bool
    {
        if (PasskeySession::skippedLocally()) {
            return true;
        }

        $step = session()->get(self::SESSION_KEY);

        return is_array($step)
            && ($step['user_id'] ?? null) === $user->id
            && is_int($step['at'] ?? null)
            && $step['at'] >= time() - (int) config('services.connect.step_up_seconds', 300);
    }

    /**
     * Uses up the confirmation: one confirmation, one grant or one key.
     *
     * @throws ValidationException
     */
    public static function consume(User $user): void
    {
        if (! self::isFresh($user)) {
            throw ValidationException::withMessages(['passkey' => __('Bekräfta med din passkey först. Bekräftelsen gäller i fem minuter.')]);
        }

        session()->forget(self::SESSION_KEY);
    }

    public static function missingPasskey(): ValidationException
    {
        return ValidationException::withMessages(['passkey' => __('Du behöver en passkey för att ge en app eller ett skript åtkomst. Lägg till en under Profil och försök igen.')]);
    }
}
