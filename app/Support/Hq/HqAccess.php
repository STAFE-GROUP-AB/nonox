<?php

declare(strict_types=1);

namespace App\Support\Hq;

use App\Actions\Auth\Passkeys\PasskeyCeremony;
use App\Actions\Auth\Passkeys\VerifyPasskeyAssertion;
use App\Models\User;
use App\Support\Auth\PasskeySession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

/**
 * Who may use /hq, the owner's area for the waiting list (ADR-038): the
 * e-mail addresses in config('nonox.hq_admins'), or app.admin_email when
 * that list is empty. Everyone else, guests included, gets a 404, so the
 * area does not reveal that it exists.
 *
 * Decisions also need a fresh passkey confirmation (the pattern of ADR-029
 * and ADR-032): one confirmation covers decisions for a few minutes.
 */
final class HqAccess
{
    private const string SESSION_KEY = 'hq.step_up';

    /** @return list<string> */
    public static function admins(): array
    {
        $admins = array_values(array_filter(array_map(
            fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            (array) config('nonox.hq_admins', []),
        )));

        if ($admins !== []) {
            return $admins;
        }

        $fallback = mb_strtolower(trim((string) config('app.admin_email')));

        return $fallback === '' ? [] : [$fallback];
    }

    public static function allows(?Authenticatable $user): bool
    {
        if (! $user instanceof User || $user->anonymised_at !== null) {
            return false;
        }

        return in_array(User::normaliseEmail($user->email), self::admins(), true);
    }

    /** Stops everyone but an HQ admin with a 404. */
    public static function ensure(?Authenticatable $user): User
    {
        abort_unless(self::allows($user), 404);

        /** @var User $user */
        return $user;
    }

    /** @return array<array-key, mixed> options for navigator.credentials.get() */
    public static function passkeyOptions(User $user): array
    {
        if (! $user->hasPasskeysEnabled()) {
            throw self::missingPasskey();
        }

        return PasskeyCeremony::startAssertion($user);
    }

    /**
     * Confirms a decision: a passkey answer given now, or one given within
     * the last few minutes. Where passkeys are skipped locally nothing is asked.
     *
     * @param  ?array<array-key, mixed>  $credential
     *
     * @throws ValidationException
     */
    public static function confirm(User $user, ?array $credential): void
    {
        if ($credential !== null) {
            app(VerifyPasskeyAssertion::class)($user, $credential);
            session()->put(self::SESSION_KEY, ['user_id' => $user->id, 'at' => now()->getTimestamp()]);

            return;
        }

        if (self::isFresh($user)) {
            return;
        }

        throw $user->hasPasskeysEnabled()
            ? ValidationException::withMessages(['passkey' => __('Bekräfta med din passkey först. Bekräftelsen gäller i fem minuter.')])
            : self::missingPasskey();
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
            && $step['at'] >= now()->getTimestamp() - (int) config('nonox.hq.step_up_seconds', 300);
    }

    public static function missingPasskey(): ValidationException
    {
        return ValidationException::withMessages(['passkey' => __('Du behöver en passkey för att fatta beslut i HQ. Lägg till en under Profil och försök igen.')]);
    }
}
