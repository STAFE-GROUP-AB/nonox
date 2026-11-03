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

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'crm:settings'), 403);

        $this->companyId = $company->id;
        $this->settings = CrmSettings::for($company);
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'crm:settings'), 403);

        $rules = [];

        foreach (CrmSettings::LIMITS as $key => [$min, $max]) {
            $rules["settings.{$key}"] = ['required', 'integer', "between:{$min},{$max}"];
        }

        $validated = Validator::make(['settings' => $this->settings], $rules, [], [
            'settings.months_to_inactive' => __('månader utan faktura'),
            'settings.prune_after_months' => __('månader utan aktivitet'),
        ])->validate();

        $modules->updateSettings(CrmModule::KEY, $company, $this->user(), array_map('intval', (array) $validated['settings']));

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
