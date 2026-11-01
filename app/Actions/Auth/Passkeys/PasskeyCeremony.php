<?php

declare(strict_types=1);

namespace App\Actions\Auth\Passkeys;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * The challenge half of a WebAuthn ceremony (ADR-029). The options sent to
 * the browser are kept in the session, bound to the user they were made for,
 * and can be answered once: a challenge is never reused.
 */
final class PasskeyCeremony
{
    private const string REGISTRATION = 'passkeys.registration';

    private const string ASSERTION = 'passkeys.assertion';

    private const int TTL_SECONDS = 300;

    /** @return array<array-key, mixed> the options for navigator.credentials.create() */
    public static function startRegistration(User $user): array
    {
        $options = app(GenerateRegistrationOptions::class)($user);

        self::remember(self::REGISTRATION, $user, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /** @return array<array-key, mixed> the options for navigator.credentials.get(), limited to the user's passkeys */
    public static function startAssertion(User $user): array
    {
        $options = app(GenerateVerificationOptions::class)($user);

        self::remember(self::ASSERTION, $user, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    public static function registrationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        return WebAuthn::fromJson(self::pull(self::REGISTRATION, $user), PublicKeyCredentialCreationOptions::class);
    }

    public static function assertionOptions(User $user): PublicKeyCredentialRequestOptions
    {
        return WebAuthn::fromJson(self::pull(self::ASSERTION, $user), PublicKeyCredentialRequestOptions::class);
    }

    /**
     * The browser's answer, as sent by resources/js/passkeys.js.
     *
     * @param  array<array-key, mixed>  $credential
     */
    public static function credential(array $credential): PublicKeyCredential
    {
        if (($credential['type'] ?? null) !== 'public-key' || ! is_string($credential['id'] ?? null) || ! is_array($credential['response'] ?? null)) {
            throw self::failed();
        }

        try {
            return WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            throw self::failed();
        }
    }

    public static function failed(?string $message = null): ValidationException
    {
        return ValidationException::withMessages([
            'passkey' => $message ?? __('Det gick inte att använda passkeyn. Försök igen.'),
        ]);
    }

    private static function remember(string $key, User $user, string $options): void
    {
        session()->put($key, ['user_id' => $user->id, 'options' => $options, 'expires_at' => time() + self::TTL_SECONDS]);
    }

    private static function pull(string $key, User $user): string
    {
        $pending = session()->pull($key);

        if (! is_array($pending) || ($pending['user_id'] ?? null) !== $user->id || ! is_string($pending['options'] ?? null) || (int) ($pending['expires_at'] ?? 0) < time()) {
            throw self::failed(__('Tiden för passkeyn gick ut. Försök igen.'));
        }

        return $pending['options'];
    }
}
