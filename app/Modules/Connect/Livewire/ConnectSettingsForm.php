<?php

declare(strict_types=1);

namespace App\Modules\Connect\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Actions\SaveConnectSettings;
use App\Modules\Connect\Models\ConnectApp;
use App\Modules\Connect\Support\ConnectSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * NONOX Connect's settings under Moduler (ADR-032): which apps may ask for
 * access, and an optional list of addresses the API may be called from.
 */
class ConnectSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'connect.settings-form';

    #[Locked]
    public int $companyId;

    public string $appPolicy = 'all';

    /** @var list<string> */
    public array $allowedClients = [];

    public string $ipAllowlist = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'connect:manage'), 403);

        $settings = ConnectSettings::for($company);
        $this->companyId = $company->id;
        $this->appPolicy = $settings['app_policy'];
        $this->allowedClients = $settings['allowed_clients'];
        $this->ipAllowlist = implode("\n", $settings['ip_allowlist']);
    }

    public function save(SaveConnectSettings $save): void
    {
        $company = Company::query()->findOrFail($this->companyId);

        $save($this->user(), $company, [
            'app_policy' => $this->appPolicy,
            'allowed_clients' => $this->allowedClients,
            'ip_allowlist' => $this->ipAllowlist,
        ]);

        $this->toast(__('Inställningarna för NONOX Connect är sparade.'));
    }

    public function render(): View
    {
        return view('connect::livewire.settings-form', [
            'clients' => ConnectApp::query()->where('revoked', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
