<?php

declare(strict_types=1);

namespace App\Modules\Agreements\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\AgreementsModule;
use App\Modules\Agreements\AgreementsSettings;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * NONOX Avtal's settings under Moduler. The binding to an agreement and the
 * employments' agreement facts are on Lön → Avtal.
 */
class AgreementsSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'agreements.settings-form';

    #[Locked]
    public int $companyId;

    public bool $minimumWageWarnings = true;

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'agreements:manage'), 403);

        $this->companyId = $company->id;
        $this->minimumWageWarnings = AgreementsSettings::bool(app(ModuleManager::class)->settings(AgreementsModule::KEY, $company), 'minimum_wage_warnings');
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'agreements:manage'), 403);

        $modules->updateSettings(AgreementsModule::KEY, $company, $this->user(), ['minimum_wage_warnings' => $this->minimumWageWarnings]);
        $this->toast(__('Inställningarna för Avtal är sparade.'));
    }

    public function render(): View
    {
        return view('agreements::settings-form');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
