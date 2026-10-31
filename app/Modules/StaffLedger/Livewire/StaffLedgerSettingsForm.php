<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\StaffLedger\StaffLedgerModule;
use App\Modules\StaffLedger\StaffLedgerSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The staff ledger's settings under Moduler: when forgotten check-outs are
 * reminded, when a shift counts as long, and how much a kiosk's clock may
 * differ before its entries are flagged. Check-in methods are set per
 * location, under Personalliggare → Lokaler.
 */
class StaffLedgerSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'staff-ledger.settings-form';

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'staff-ledger:settings'), 403);

        $this->companyId = $company->id;
        $this->settings = [...StaffLedgerSettings::DEFAULTS, ...app(ModuleManager::class)->settings(StaffLedgerModule::KEY, $company)];
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'staff-ledger:settings'), 403);

        $rules = [];

        foreach (StaffLedgerSettings::LIMITS as $key => [$min, $max]) {
            $rules["settings.{$key}"] = ['required', 'integer', "between:{$min},{$max}"];
        }

        $validated = Validator::make(['settings' => $this->settings], $rules, [], [
            'settings.reminder_after_hours' => __('Påminn om glömd utcheckning efter'),
            'settings.long_shift_hours' => __('Långt arbetspass efter'),
            'settings.clock_skew_minutes' => __('Tillåten skillnad i kioskens klocka'),
        ])->validate();

        $modules->updateSettings(StaffLedgerModule::KEY, $company, $this->user(), array_map('intval', $validated['settings']));
        $this->toast(__('Inställningarna för personalliggaren är sparade.'));
    }

    public function render(): View
    {
        return view('staff-ledger::settings-form');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
