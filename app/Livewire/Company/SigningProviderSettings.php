<?php

declare(strict_types=1);

namespace App\Livewire\Company;

use App\Actions\Signing\ManageSigningProviders;
use App\Actions\Signing\SignWithProvider;
use App\Domain\Signing\Pdf\PdfEngineStatus;
use App\Domain\Signing\Providers\Csc\CscProvider;
use App\Domain\Signing\Providers\ProviderRegistry;
use App\Domain\Signing\Providers\SignatureLevel;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\SigningProvider;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Inställningar för företaget → Signering → "Underskrift hos extern tjänst"
 * (ADR-046 amendment, gap 12, package S17): the company's signing services
 * (a CSC API v2 remote signing service), whether external signing can be
 * used on this installation, the return address to register with the
 * service, and adding or retiring a service. Needs signing:settings.
 *
 * The client secret is typed into a field bound without live updates, so
 * it travels only with the "Lägg till" request, and it is emptied right
 * after, whether the service is accepted or not: it never stays in the
 * component's state. It is never shown again.
 */
class SigningProviderSettings extends Component
{
    use ShowsToasts;

    #[Locked]
    public int $companyId;

    public string $driver = CscProvider::KEY;

    public string $name = '';

    public string $baseUrl = '';

    public string $clientId = '';

    public string $clientSecret = '';

    public string $claimedLevel = 'QES';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'signing:settings'), 403);

        $this->companyId = $company->id;
    }

    public function add(ManageSigningProviders $manage): void
    {
        $this->resetErrorBag();

        try {
            $manage->add($this->user(), $this->company(), [
                'driver' => $this->driver,
                'name' => $this->name,
                'base_url' => $this->baseUrl,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'claimed_level' => $this->claimedLevel,
            ]);
        } catch (ValidationException $exception) {
            $this->clientSecret = '';

            throw ValidationException::withMessages(self::fieldErrors($exception->errors()));
        }

        $this->clientSecret = '';
        $this->reset(['name', 'baseUrl', 'clientId']);
        $this->toast(__('Tjänsten för underskrift är tillagd. Den kan väljas som kontroll när du skickar ett dokument för signering.'));
    }

    public function retire(int $providerId, ManageSigningProviders $manage): void
    {
        $manage->retire($this->user(), $this->company(), $providerId);

        $this->toast(__('Tjänsten används inte längre, och dess klienthemlighet är borttagen.'));
    }

    public function render(): View
    {
        $company = $this->company();

        [$active, $retired] = CurrentCompany::run($company, fn (): array => [
            SigningProvider::query()->active()->orderBy('name')->get(['id', 'driver', 'name', 'base_url', 'claimed_level', 'service_name', 'service_region', 'created_at']),
            SigningProvider::query()->whereNotNull('retired_at')->orderByDesc('retired_at')->limit(5)->get(['id', 'name', 'claimed_level', 'retired_at']),
        ]);

        return view('livewire.company.signing-provider-settings', [
            'active' => $active,
            'retired' => $retired,
            'switchedOn' => ProviderRegistry::switchedOn(),
            'engine' => app(PdfEngineStatus::class)->usable(),
            'drivers' => ProviderRegistry::keys(),
            'levels' => SignatureLevel::cases(),
            'returnUrl' => SignWithProvider::redirectUri(),
            'zone' => (string) config('app.timezone'),
        ]);
    }

    /**
     * The action's attribute names mapped to this form's properties, so each error shows at its field.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private static function fieldErrors(array $errors): array
    {
        $map = ['base_url' => 'baseUrl', 'client_id' => 'clientId', 'client_secret' => 'clientSecret', 'claimed_level' => 'claimedLevel'];
        $mapped = [];

        foreach ($errors as $key => $messages) {
            $mapped[$map[$key] ?? $key] = $messages;
        }

        return $mapped;
    }

    private function company(): Company
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'signing:settings'), 403);

        return $company;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
