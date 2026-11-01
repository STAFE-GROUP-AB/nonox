<?php

declare(strict_types=1);

namespace App\Modules\Connect\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Actions\CreateApiKey;
use App\Modules\Connect\Actions\RevokeApiKey;
use App\Modules\Connect\Actions\RevokeConnection;
use App\Modules\Connect\Models\ConnectApiKey;
use App\Modules\Connect\Models\ConnectGrant;
use App\Modules\Connect\Support\ConnectScopes;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Support\Auth\PasskeySession;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Anslutningar (ADR-032): the apps connected to the company (who connected
 * them, what they may do, when they were last used) and the company's API
 * keys. Everyone with connect:view sees them; owners and administrators
 * create keys (with a passkey) and revoke apps and keys.
 */
class ConnectionsManager extends Component
{
    use ShowsToasts;

    public const string NAME = 'connect.connections-manager';

    #[Locked]
    public int $companyId;

    public string $keyName = '';

    /** @var list<string> */
    public array $keyScopes = [];

    public string $keyExpiresOn = '';

    public string $keyIpAllowlist = '';

    /** The new key, shown once and then forgotten. */
    #[Locked]
    public ?string $newKey = null;

    public function mount(): void
    {
        $company = $this->company();
        abort_unless($this->user()->hasTeamPermission($company, 'connect:view'), 403);

        $this->companyId = $company->id;
        $this->keyExpiresOn = CarbonImmutable::today()->addDays(90)->toDateString();
    }

    /** @return array<array-key, mixed> */
    public function passkeyOptions(): array
    {
        return PasskeyStepUp::start($this->user());
    }

    /**
     * @param  ?array<array-key, mixed>  $credential  the passkey's answer; null only where passkeys are skipped locally
     */
    public function createKey(?array $credential, CreateApiKey $create): void
    {
        $user = $this->user();

        if ($credential !== null) {
            PasskeyStepUp::confirm($user, $credential);
        } elseif (! PasskeySession::skippedLocally()) {
            throw PasskeyStepUp::missingPasskey();
        }

        $result = $create($user, $this->company(), [
            'name' => $this->keyName,
            'scopes' => $this->keyScopes,
            'expires_on' => $this->keyExpiresOn,
            'ip_allowlist' => $this->keyIpAllowlist,
        ]);

        $this->newKey = $result['plaintext'];
        $this->reset(['keyName', 'keyScopes', 'keyIpAllowlist']);
        $this->toast(__('API-nyckeln :name är skapad. Kopiera den nu, den visas bara en gång.', ['name' => $result['key']->name]));
    }

    public function forgetNewKey(): void
    {
        $this->newKey = null;
    }

    public function revokeKey(int $keyId, RevokeApiKey $revoke): void
    {
        $company = $this->company();
        $key = CurrentCompany::run($company, fn (): ConnectApiKey => ConnectApiKey::query()->findOrFail($keyId));
        $revoke($this->user(), $company, $key);

        $this->toast(__('API-nyckeln :name är återkallad och slutar fungera direkt.', ['name' => $key->name]));
    }

    public function revokeGrant(string $grantId, RevokeConnection $revoke): void
    {
        $company = $this->company();
        $grant = CurrentCompany::run($company, fn (): ConnectGrant => ConnectGrant::query()->with('client')->findOrFail($grantId));
        $revoke($this->user(), $company, $grant);

        $this->toast(__(':app är bortkopplad. Appen kommer inte längre åt företaget.', ['app' => $grant->client->name ?? __('Appen')]));
    }

    public function render(): View
    {
        $company = $this->company();
        $user = $this->user();

        return view('connect::livewire.connections-manager', CurrentCompany::run($company, fn (): array => [
            'grants' => ConnectGrant::query()->active()->with(['client', 'user'])->latest()->get(),
            'keys' => ConnectApiKey::query()->whereNull('revoked_at')->with('creator')->orderBy('expires_at')->get(),
            'scopeLabels' => ConnectScopes::labels(),
            'canManage' => $user->hasTeamPermission($company, 'connect:manage'),
            'hasPasskey' => $user->hasPasskeysEnabled(),
            'skipPasskey' => PasskeySession::skippedLocally(),
            'userId' => $user->id,
        ]));
    }

    private function company(): Company
    {
        $company = isset($this->companyId) ? Company::query()->find($this->companyId) : $this->user()->currentCompany();
        abort_unless($company instanceof Company, 404);

        return $company;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
