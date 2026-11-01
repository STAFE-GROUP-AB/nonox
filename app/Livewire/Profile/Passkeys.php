<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Actions\Auth\Passkeys\PasskeyCeremony;
use App\Actions\Auth\Passkeys\RegisterPasskey;
use App\Actions\Auth\Passkeys\RemovePasskey;
use App\Domain\Auth\PasskeyRequirement;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\User;
use App\Support\Auth\PasskeySession;
use App\Support\Tenancy\SpansCompanies;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "Passkeys" on the profile (ADR-029): add one with a name, see when each was
 * added and last used, remove one. The last passkey cannot be removed while
 * a passkey is required for the user.
 */
class Passkeys extends Component implements SpansCompanies
{
    use ShowsToasts;

    public string $name = '';

    /** @return array<array-key, mixed> */
    public function registrationOptions(): array
    {
        $this->validate(['name' => ['required', 'string', 'max:60']], attributes: ['name' => __('namn')]);

        return PasskeyCeremony::startRegistration($this->user());
    }

    /**
     * @param  array<array-key, mixed>  $credential
     */
    public function register(array $credential, RegisterPasskey $register): void
    {
        $passkey = $register($this->user(), $this->name, $credential);

        // Creating it verified the user on the device, which is as good as signing in with it.
        PasskeySession::markVerified();

        $this->reset('name');
        $this->toast(__('Passkeyn ":name" är tillagd.', ['name' => $passkey->name]));
    }

    public function remove(int $passkeyId, RemovePasskey $remove): void
    {
        $passkey = $remove($this->user(), $passkeyId);

        $this->toast(__('Passkeyn ":name" är borttagen.', ['name' => $passkey->name]));
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.profile.passkeys', [
            'passkeys' => $user->passkeys()->orderBy('created_at')->get(['id', 'name', 'credential', 'created_at', 'last_used_at']),
            'required' => PasskeyRequirement::appliesTo($user),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
