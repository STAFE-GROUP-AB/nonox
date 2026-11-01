<?php

declare(strict_types=1);

namespace App\Actions\Auth\Passkeys;

use App\Mail\PasskeyAddedMail;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Passkey;
use Throwable;

/**
 * Adds a passkey to the user's account after the browser created it
 * (ADR-029). The authenticator must verify the user (PIN, fingerprint, face),
 * attestation is not requested. Only the public credential is stored. The
 * user gets an email, so a passkey added by someone else does not go unseen.
 */
class RegisterPasskey
{
    public const int MAX_PER_USER = 20;

    /**
     * @param  array<array-key, mixed>  $credential
     */
    public function __invoke(User $user, string $name, array $credential): Passkey
    {
        $name = trim($name);

        Validator::make(
            ['name' => $name],
            ['name' => ['required', 'string', 'max:60']],
            attributes: ['name' => __('namn')],
        )->validate();

        if ($user->passkeys()->count() >= self::MAX_PER_USER) {
            throw PasskeyCeremony::failed(__('Du kan ha högst :count passkeys. Ta bort en du inte använder först.', ['count' => self::MAX_PER_USER]));
        }

        $options = PasskeyCeremony::registrationOptions($user);
        $publicKeyCredential = PasskeyCeremony::credential($credential);

        try {
            $passkey = app(StorePasskey::class)($user, $name, $publicKeyCredential, $options);
        } catch (ValidationException) {
            throw PasskeyCeremony::failed(__('Passkeyn kunde inte läggas till. Den kanske redan finns på ditt konto.'));
        } catch (Throwable) {
            throw PasskeyCeremony::failed();
        }

        // Name and authenticator only: never the credential itself.
        Audit::record('passkey.added', $passkey, ['name' => $passkey->name, 'authenticator' => $passkey->authenticator], null, $user);

        Mail::to($user->email)->send(new PasskeyAddedMail($user->name, $passkey->name));

        return $passkey;
    }
}
