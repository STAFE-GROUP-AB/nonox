<?php

declare(strict_types=1);

namespace App\Livewire\Auth\Concerns;

use App\Actions\Auth\Passkeys\PasskeyCeremony;
use App\Actions\Auth\Passkeys\RegisterPasskey;
use App\Actions\Auth\Passkeys\VerifyPasskeyAssertion;
use App\Models\User;
use Livewire\Component;

/**
 * The passkey step (ADR-029), shared by the sign-in form (before the session
 * starts) and the passkey page (a signed-in session that has to pass it).
 * A user with passkeys must use one; a new passkey can be added here only by
 * a user who has none yet. Anyone with passkeys adds more from the profile,
 * after signing in with one, so a stolen mailbox cannot add its own.
 *
 * @mixin Component
 */
trait HandlesPasskeyStep
{
    public string $passkeyName = '';

    /** The user the step is for, or null when the step has expired. */
    abstract protected function passkeyUser(): ?User;

    abstract protected function passkeyPassed(User $user): void;

    private function passkeyUserOrFail(): User
    {
        return $this->passkeyUser() ?? throw PasskeyCeremony::failed(__('Tiden för inloggningen gick ut. Börja om och logga in igen.'));
    }

    /** @return array<array-key, mixed> */
    public function passkeyAssertionOptions(): array
    {
        $user = $this->passkeyUserOrFail();

        return PasskeyCeremony::startAssertion($user);
    }

    /**
     * @param  array<array-key, mixed>  $credential
     */
    public function confirmPasskey(array $credential): void
    {
        $user = $this->passkeyUserOrFail();

        app(VerifyPasskeyAssertion::class)($user, $credential);

        $this->passkeyPassed($user);
    }

    /** @return array<array-key, mixed> */
    public function passkeyRegistrationOptions(): array
    {
        $user = $this->passkeyUserOrFail();

        abort_if($user->hasPasskeysEnabled(), 403);

        $this->validate(['passkeyName' => ['required', 'string', 'max:60']], attributes: ['passkeyName' => __('namn')]);

        return PasskeyCeremony::startRegistration($user);
    }

    /**
     * @param  array<array-key, mixed>  $credential
     */
    public function registerFirstPasskey(array $credential): void
    {
        $user = $this->passkeyUserOrFail();

        abort_if($user->hasPasskeysEnabled(), 403);

        app(RegisterPasskey::class)($user, $this->passkeyName, $credential);

        $this->passkeyPassed($user);
    }
}
