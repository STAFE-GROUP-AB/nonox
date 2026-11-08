<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Payroll\PayrollSettings;
use App\Modules\Payroll\Support\Agi\AgiDeadlines;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The payroll module's settings (PAYROLL_MODULE.md B.3), shown under
 * Moduler when payroll is on.
 */
class PayrollSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'payroll.settings-form';

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'payroll:settings'), 403);

        $this->companyId = $company->id;
        $this->settings = [...PayrollSettings::DEFAULTS, ...app(ModuleManager::class)->settings(PayrollModule::KEY, $company)];
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'payroll:settings'), 403);

        $rules = [];

        foreach (PayrollSettings::OPTIONS as $key => $values) {
            if (in_array($key, PayrollSettings::DERIVED, true)) {
                continue;
            }

            $rules["settings.{$key}"] = match (true) {
                $values !== null => ['required', Rule::in($values)],
                $key === 'default_payment_day' => ['required', 'integer', 'between:1,28'],
                $key === 'vacation_year_start_month' => ['required', 'integer', 'between:1,12'],
                $key === 'vacation_day_value_percent' => ['required', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
                in_array($key, ['mileage_own_car_rate', 'mileage_company_car_rate'], true) => ['nullable', 'regex:/^\d{1,4}([.,]\d{1,2})?$/'],
                $key === 'mileage_claim_last_day' => ['nullable', 'integer', 'between:1,31'],
                default => ['boolean'],
            };
        }

        $validated = Validator::make(['settings' => array_diff_key($this->settings, array_flip(PayrollSettings::DERIVED))], $rules)->validate();

        $modules->updateSettings(PayrollModule::KEY, $company, $this->user(), $validated['settings']);
        $this->toast(__('Löneinställningarna är sparade.'));
    }

    public function render(): View
    {
        $company = Company::query()->findOrFail($this->companyId);

        return view('payroll::settings-form', [
            'options' => PayrollSettings::OPTIONS,
            'agiGroup' => AgiDeadlines::groupFor($company),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
