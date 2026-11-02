<?php

declare(strict_types=1);

namespace App\Modules\Connect\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Connect\Actions\AddSftpKey;
use App\Modules\Connect\Actions\PutSftpExport;
use App\Modules\Connect\Actions\RemoveSftpKey;
use App\Modules\Connect\Actions\SaveSftpAllowlist;
use App\Modules\Connect\Actions\SetSftpEnabled;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Models\ConnectSftpKey;
use App\Modules\Connect\Models\ConnectSftpUpload;
use App\Modules\Connect\Sftp\SftpAccess;
use App\Modules\Connect\Sftp\SftpConfig;
use App\Modules\Connect\Support\PasskeyStepUp;
use App\Support\Auth\PasskeySession;
use App\Support\Tenancy\CurrentCompany;
use App\Support\WorkQuota;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * SFTP under Anslutningar (ADR-032, "SFTP"): the company's SFTP drop for
 * SIE files. Everyone with connect:view sees the address, the keys and the
 * upload log; owners and administrators turn it on and off, add keys and
 * change the allowed addresses (both with a passkey), remove keys and put
 * a SIE export out for download.
 */
class SftpManager extends Component
{
    use ShowsToasts;

    public const string NAME = 'connect.sftp-manager';

    #[Locked]
    public int $companyId;

    public string $keyName = '';

    public string $publicKey = '';

    public string $allowlist = '';

    public function mount(): void
    {
        $company = $this->company();
        abort_unless($this->user()->hasTeamPermission($company, 'connect:view'), 403);

        $this->companyId = $company->id;
        $this->allowlist = implode("\n", $this->account()->allowed_cidrs ?? []);
    }

    /** @return array<array-key, mixed> */
    public function passkeyOptions(): array
    {
        return PasskeyStepUp::start($this->user());
    }

    public function enable(SetSftpEnabled $set): void
    {
        $set($this->user(), $this->company(), true);
        $this->toast(__('SFTP är påslaget. Lägg till nyckeln som ska få logga in.'));
    }

    public function disable(SetSftpEnabled $set): void
    {
        $set($this->user(), $this->company(), false);
        $this->toast(__('SFTP är avstängt. Ingen kan logga in förrän du slår på det igen.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential  the passkey's answer; null only where passkeys are skipped locally
     */
    public function addKey(?array $credential, AddSftpKey $add): void
    {
        $this->resetErrorBag();
        $this->confirmPasskey($credential);

        $key = $add($this->user(), $this->company(), ['name' => $this->keyName, 'public_key' => $this->publicKey]);

        $this->reset(['keyName', 'publicKey']);
        $this->toast(__('Nyckeln :fingerprint är tillagd och kan logga in.', ['fingerprint' => $key->fingerprint]));
    }

    public function removeKey(string $keyId, RemoveSftpKey $remove): void
    {
        abort_unless(Str::isUuid($keyId), 404);

        $key = CurrentCompany::run($this->company(), fn (): ConnectSftpKey => ConnectSftpKey::query()->findOrFail($keyId));
        $remove($this->user(), $this->company(), $key);

        $this->toast(__('Nyckeln är borttagen och kan inte logga in längre.'));
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    public function saveAllowlist(?array $credential, SaveSftpAllowlist $save): void
    {
        $this->resetErrorBag();
        $this->confirmPasskey($credential);

        $entries = $save($this->user(), $this->company(), $this->allowlist);
        $this->allowlist = implode("\n", $entries);

        $this->toast($entries === []
            ? __('Tillåtna adresser är sparade. Alla adresser får ansluta.')
            : __('Tillåtna adresser är sparade. Bara de får ansluta från och med nästa inloggning.'));
    }

    public function putExport(PutSftpExport $put): void
    {
        $name = $put($this->user(), $this->company());

        $this->toast(__(':file ligger i /ut i :days dagar.', ['file' => $name, 'days' => SftpConfig::exportDays()]));
    }

    public function render(): View
    {
        $company = $this->company();
        $user = $this->user();
        $account = $this->account();

        return view('connect::livewire.sftp-manager', [
            'account' => $account,
            'keys' => $account === null ? collect() : CurrentCompany::run($company, fn () => ConnectSftpKey::query()
                ->with('creator')
                ->active()
                ->where('account_id', $account->id)
                ->orderBy('created_at')
                ->get()),
            'uploads' => $account === null ? collect() : CurrentCompany::run($company, fn () => ConnectSftpUpload::query()
                ->where('account_id', $account->id)
                ->latest()
                ->limit(20)
                ->get()),
            'available' => SftpConfig::available(),
            'host' => SftpConfig::host(),
            'port' => SftpConfig::port(),
            'hostFingerprints' => SftpConfig::hostFingerprints(),
            'sftpgoSource' => SftpConfig::sftpgoSourceUrl(),
            'maxMegabytes' => intdiv(SftpConfig::maxFileBytes(), 1024 * 1024),
            'exportDays' => SftpConfig::exportDays(),
            'hourlyLimit' => WorkQuota::LIMITS['sie_upload'],
            'extensions' => SftpConfig::EXTENSIONS,
            'refusal' => $account === null ? null : SftpAccess::refusal($account, $company, checkIp: false),
            'canManage' => $user->hasTeamPermission($company, 'connect:manage'),
            'canImport' => $user->hasTeamPermission($company, 'import:sie'),
            'hasPasskey' => $user->hasPasskeysEnabled(),
            'skipPasskey' => PasskeySession::skippedLocally(),
        ]);
    }

    /**
     * @param  ?array<array-key, mixed>  $credential
     */
    private function confirmPasskey(?array $credential): void
    {
        if ($credential !== null) {
            PasskeyStepUp::confirm($this->user(), $credential);

            return;
        }

        if (! PasskeySession::skippedLocally()) {
            throw PasskeyStepUp::missingPasskey();
        }
    }

    private function account(): ?ConnectSftpAccount
    {
        return CurrentCompany::run($this->company(), fn (): ?ConnectSftpAccount => ConnectSftpAccount::query()->with('responsible')->first());
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
