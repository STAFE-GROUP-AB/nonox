<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Auth\Concerns\HandlesPasskeyStep;
use App\Models\User;
use App\Support\Auth\PasskeySession;
use App\Support\Tenancy\SpansCompanies;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The passkey page for a signed-in session that has not passed the passkey
 * step but must (RequirePasskey, ADR-029): use a passkey, or add the first
 * one. Everything else stays closed until then.
 */
class PasskeyChallenge extends Component implements SpansCompanies
{
    use HandlesPasskeyStep;

    public function mount(): void
    {
        $user = Auth::user();

        if ($user instanceof User && ! PasskeySession::needsStep($user)) {
            $this->redirectIntended(route('dashboard'));
        }
    }

    protected function passkeyUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    protected function passkeyPassed(User $user): void
    {
        session()->regenerate();
        PasskeySession::markVerified();

        Toast::flash(__('Klart. Du är inloggad med passkey.'));

        $this->redirectIntended(route('dashboard'));
    }

    public function render(): View
    {
        $user = $this->passkeyUser();

        return view('livewire.auth.passkey-challenge', [
            'step' => $user?->hasPasskeysEnabled() ? 'passkey' : 'passkey-register',
        ]);
    }
}
