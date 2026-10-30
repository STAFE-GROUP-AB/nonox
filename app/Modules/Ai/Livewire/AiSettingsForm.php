<?php

declare(strict_types=1);

namespace App\Modules\Ai\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Ai\AiModule;
use App\Modules\Ai\Support\AiProvider;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * NONOX AI's settings under Moduler: which AI provider the company uses, the
 * model, and the company's own API key per provider. A key is checked with the
 * provider before it is saved, stored encrypted and never shown again; only
 * its last four characters.
 */
class AiSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'ai.settings-form';

    #[Locked]
    public int $companyId;

    public string $provider = 'OPENAI';

    public string $modelChoice = 'CHEAPEST';

    public string $model = '';

    public string $apiKey = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'ai:settings'), 403);

        $settings = app(ModuleManager::class)->settings(AiModule::KEY, $company);
        $this->companyId = $company->id;
        $this->provider = (AiProvider::tryFrom((string) ($settings['provider'] ?? '')) ?? AiProvider::OpenAi)->value;
        $this->modelChoice = in_array($settings['model_choice'] ?? null, AiModule::MODEL_CHOICES, true) ? (string) $settings['model_choice'] : 'CHEAPEST';
        $this->model = (string) ($settings['model'] ?? '');
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'ai:settings'), 403);

        $this->validate([
            'provider' => ['required', Rule::enum(AiProvider::class)],
            'modelChoice' => ['required', Rule::in(AiModule::MODEL_CHOICES)],
            'model' => [Rule::requiredIf($this->modelChoice === 'CUSTOM'), 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9.\-_:\/]+$/'],
        ], ['model.required' => __('Skriv namnet på modellen, eller välj den billigaste.')]);

        $provider = AiProvider::from($this->provider);
        $keys = (array) ($modules->settings(AiModule::KEY, $company)['api_keys'] ?? []);
        $key = trim($this->apiKey);

        if ($key !== '') {
            if (preg_match($provider->keyPattern(), $key) !== 1) {
                throw ValidationException::withMessages(['apiKey' => __('Det ser inte ut som en API-nyckel från :provider. Kopiera hela nyckeln från länken ovan.', ['provider' => $provider->label()])]);
            }

            if ($provider->accepts($key) === false) {
                throw ValidationException::withMessages(['apiKey' => __(':provider godtog inte nyckeln. Kontrollera att du kopierat hela nyckeln och att kontot har betalning inlagd.', ['provider' => $provider->label()])]);
            }

            $keys[$provider->value] = Crypt::encryptString($key);
        }

        if (! isset($keys[$provider->value])) {
            throw ValidationException::withMessages(['apiKey' => __('Lägg in en API-nyckel från :provider.', ['provider' => $provider->label()])]);
        }

        $modules->updateSettings(AiModule::KEY, $company, $this->user(), [
            'provider' => $provider->value,
            'model_choice' => $this->modelChoice,
            'model' => trim($this->model) === '' ? null : trim($this->model),
            'api_keys' => $keys,
        ]);
        $this->apiKey = '';
        $this->toast(__('NONOX AI använder nu :provider.', ['provider' => $provider->label()]));
    }

    public function render(): View
    {
        $keys = (array) (app(ModuleManager::class)->settings(AiModule::KEY, Company::query()->findOrFail($this->companyId))['api_keys'] ?? []);
        $provider = AiProvider::tryFrom($this->provider) ?? AiProvider::OpenAi;
        $stored = $keys[$provider->value] ?? null;

        return view('ai::livewire.settings-form', [
            'providers' => AiProvider::cases(),
            'current' => $provider,
            'models' => $provider->models(),
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
