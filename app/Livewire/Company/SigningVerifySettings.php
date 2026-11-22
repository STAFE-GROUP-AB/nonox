<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Signing\ManageSigningVerify;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Support\Instance\InstanceSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Inställningar för företaget → Signering → Kontrollsidan (ADR-046
 * amendment, gap 6, package S12): whether recipients may check a file on the
 * public page /kontrollera. Off by default. Needs signing:settings.
 */
class SigningVerifySettings extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public bool $enabled = false;

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'signing:settings'), 403);

        $this->companyId = $company->id;
        $this->enabled = (bool) $company->signing_verify_enabled;
    }

    public function save(ManageSigningVerify $manage): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        $manage($this->user(), $company, $this->enabled);

        $this->toast($this->enabled
            ? __('Mottagare kan nu kontrollera filer på kontrollsidan.')
            : __('Kontrollsidan svarar inte längre för företagets signeringar.'));
    }

    public function render(): View
    {
        return view('livewire.company.signing-verify-settings', [
            'instanceOn' => InstanceSettings::current()->signingVerifyEnabled(),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
