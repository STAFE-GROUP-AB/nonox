<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Kräv passkey för alla i företaget" (ADR-029). Only the owner may change
 * it: it decides how every member signs in.
 */
class PasskeyPolicy extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public bool $required = false;

    public function mount(Company $company): void
    {
        abort_unless($this->user()->ownsTeam($company), 403);

        $this->companyId = $company->id;
        $this->required = (bool) $company->require_passkeys;
    }

    public function save(): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        abort_unless($this->user()->ownsTeam($company), 403);

        if ((bool) $company->require_passkeys === $this->required) {
            return;
        }

        $company->forceFill(['require_passkeys' => $this->required])->save();

        Audit::record('company.passkeys_required_changed', $company, ['before' => ! $this->required, 'after' => $this->required], $company);

        $this->toast($this->required
            ? __('Alla i företaget måste nu logga in med passkey. Den som saknar en får lägga till en vid nästa inloggning.')
            : __('Passkey krävs inte längre för alla i företaget, bara för dem som kan se känsliga personuppgifter.'));
    }

    public function render(): View
    {
        return view('livewire.company.passkey-policy');
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
