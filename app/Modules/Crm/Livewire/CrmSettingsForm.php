<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * CRM's settings, on CRM → Inställningar and under Moduler: when an active
 * customer becomes inactive, and when idle contact people and prospects are
 * erased.
 */
class CrmSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'crm.settings-form';

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    /** The company's privacy policy, linked from web forms and mailings (ADR-048). */
    public string $privacyUrl = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'crm:settings'), 403);

        $this->companyId = $company->id;
        $this->settings = CrmSettings::for($company);
        $this->privacyUrl = (string) CrmSettings::privacyUrl($company);
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'crm:settings'), 403);

        $rules = [];

        foreach (CrmSettings::LIMITS as $key => [$min, $max]) {
            $rules["settings.{$key}"] = ['required', 'integer', "between:{$min},{$max}"];
        }

        $rules['privacy_url'] = ['nullable', 'url:https', 'max:500'];

        $validated = Validator::make(['settings' => $this->settings, 'privacy_url' => trim($this->privacyUrl)], $rules, [
            'privacy_url.url' => __('Ange en fullständig adress till er integritetspolicy som börjar med https://.'),
        ], [
            'privacy_url' => __('länk till integritetspolicyn'),
            'settings.months_to_inactive' => __('månader utan faktura'),
            'settings.prune_after_months' => __('månader utan aktivitet'),
            'settings.suppression_review_years' => __('år mellan granskningarna'),
            'settings.win_back_months' => __('månader till vinn tillbaka'),
        ])->validate();

        $modules->updateSettings(CrmModule::KEY, $company, $this->user(), [
            ...array_map('intval', (array) $validated['settings']),
            'privacy_url' => blank($validated['privacy_url'] ?? null) ? null : (string) $validated['privacy_url'],
        ]);

        $this->toast(__('Inställningarna för CRM är sparade.'));
    }

    public function render(): View
    {
        return view('crm::livewire.settings-form');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
