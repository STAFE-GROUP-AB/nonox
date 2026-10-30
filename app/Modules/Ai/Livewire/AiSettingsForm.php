<?php

declare(strict_types=1);

namespace App\Modules\Ai\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ai\AiModule;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * NONOX AI's settings under Moduler: the provider, its quality and the
 * company's own API key. The key is stored encrypted and never shown again;
 * only its last four characters.
 */
class AiSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'ai.settings-form';

    #[Locked]
    public int $companyId;

    public string $provider = 'GOZERO_CORE';

    public string $quality = 'standard';

    public string $apiKey = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'ai:settings'), 403);

        $settings = app(ModuleManager::class)->settings(AiModule::KEY, $company);
        $this->companyId = $company->id;
        $this->provider = (string) ($settings['provider'] ?? 'GOZERO_CORE');
        $this->quality = (string) ($settings['quality'] ?? 'standard');
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'ai:settings'), 403);

        Validator::make(['provider' => $this->provider, 'quality' => $this->quality, 'api_key' => $this->apiKey], [
            'provider' => ['required', Rule::in(array_keys(AiModule::PROVIDERS))],
            'quality' => ['required', Rule::in(['standard', 'high'])],
            'api_key' => ['nullable', 'string', 'regex:/^gzc_(live|test)_[A-Za-z0-9]{20,}$/'],
        ], ['api_key.regex' => __('Nyckeln ska börja med gzc_live_ eller gzc_test_.')])->validate();

        $settings = ['provider' => $this->provider, 'quality' => $this->quality];

        if (trim($this->apiKey) !== '') {
            $settings['api_key'] = Crypt::encryptString(trim($this->apiKey));
        }

        $modules->updateSettings(AiModule::KEY, $company, $this->user(), $settings);
        $this->apiKey = '';
        $this->toast(__('Inställningarna för NONOX AI är sparade.'));
    }

    public function render(): View
    {
        $stored = app(ModuleManager::class)->settings(AiModule::KEY, Company::query()->findOrFail($this->companyId))['api_key'] ?? null;

        return view('ai::livewire.settings-form', [
            'keyEnding' => is_string($stored) ? substr(Crypt::decryptString($stored), -4) : null,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
