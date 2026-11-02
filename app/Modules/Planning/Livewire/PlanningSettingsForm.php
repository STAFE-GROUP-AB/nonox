<?php

declare(strict_types=1);

namespace App\Modules\Planning\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Support\CompTimeLedger;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Planering's settings on Planering → Inställningar, and under Moduler
 * (PLANNING_MODULE.md §3): the view and
 * time step, publishing and notifications, staff self-service, the
 * working-time and minors' rule values, attest tolerance and which revenue
 * accounts count as sales. Areas and skills are on Planering → Inställningar.
 */
class PlanningSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'planning.settings-form';

    /** Settings that change what is sent to payroll: planning:attest only. */
    public const array PAYROLL_FIELDS = ['attest_tolerance_minutes', 'check_in_rounding_minutes', 'overtime_pay_code', 'extra_time_pay_code', 'overtime_period_weeks', 'overtime_period_start', 'comp_time_factor'];

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'planning:manage'), 403);

        $this->companyId = $company->id;
        $this->settings = PlanningSettings::resolve(app(ModuleManager::class)->settings(PlanningModule::KEY, $company));
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'planning:manage'), 403);

        $rules = [
            'settings.default_view' => ['required', Rule::in(PlanningSettings::VIEWS)],
            'settings.time_step_minutes' => ['required', 'integer', Rule::in(PlanningSettings::TIME_STEPS)],
        ];

        foreach (PlanningSettings::LIMITS as $key => [$min, $max]) {
            $rules["settings.{$key}"] = ['required', 'integer', "between:{$min},{$max}"];
        }

        // Fields that change pay can only be changed by someone who attests time to payroll.
        $canAttest = $this->user()->hasTeamPermission($company, 'planning:attest');
        $stored = PlanningSettings::resolve($modules->settings(PlanningModule::KEY, $company));

        if (! $canAttest) {
            foreach (self::PAYROLL_FIELDS as $key) {
                $this->settings[$key] = $stored[$key];
            }
        }

        foreach (['notify_on_publish', 'notify_on_change', 'allow_open_shift_claims', 'allow_swaps', 'allow_availability', 'minors_enabled'] as $key) {
            $rules["settings.{$key}"] = ['boolean'];
        }

        foreach (['overtime_pay_code', 'extra_time_pay_code'] as $key) {
            $rules["settings.{$key}"] = ['nullable', 'string', Rule::in(array_keys(self::payCodes()))];
        }

        $rules['settings.overtime_period_start'] = ['nullable', 'date_format:Y-m-d'];
        $rules['settings.comp_time_factor'] = ['required', 'regex:/^\\d([.,]\\d{1,2})?$/'];

        foreach (['working_time_rules' => PlanningSettings::WORKING_TIME_RULES, 'minors_rules' => PlanningSettings::MINORS_RULES] as $set => $defaults) {
            foreach ($defaults as $key => $default) {
                $rules["settings.{$set}.{$key}.enabled"] = ['boolean'];
                $rules["settings.{$set}.{$key}.value"] = match ($default['unit']) {
                    'time' => ['required', 'date_format:H:i'],
                    'none' => ['nullable', 'integer'],
                    default => ['required', 'integer', 'between:0,100000'],
                };
            }
        }

        $validated = Validator::make(['settings' => $this->settings], $rules, [], [
            'settings.sales_account_from' => __('Första intäktskonto'),
            'settings.sales_account_to' => __('Sista intäktskonto'),
            'settings.publish_ahead_days' => __('Publicera i förväg'),
            'settings.attest_tolerance_minutes' => __('Tolerans vid attest'),
            'settings.overtime_period_weeks' => __('Beräkningsperiod'),
            'settings.overtime_period_start' => __('Första periodens måndag'),
            'settings.comp_time_factor' => __('Komptid per timme'),
        ])->after(function ($validator): void {
            $start = $this->settings['overtime_period_start'] ?? null;

            if (is_string($start) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $start) === 1 && ! CarbonImmutable::parse($start)->isMonday()) {
                $validator->errors()->add('settings.overtime_period_start', __('Perioden ska börja en måndag.'));
            }

            $factor = (float) str_replace(',', '.', (string) ($this->settings['comp_time_factor'] ?? '1'));

            if ($factor <= 0 || $factor > 3) {
                $validator->errors()->add('settings.comp_time_factor', __('Ange en faktor mellan 0,01 och 3.'));
            }
        })->validate()['settings'];

        $store = [
            'default_view' => $validated['default_view'],
            'time_step_minutes' => (int) $validated['time_step_minutes'],
        ];

        foreach (array_keys(PlanningSettings::LIMITS) as $key) {
            $store[$key] = (int) $validated[$key];
        }

        foreach (['notify_on_publish', 'notify_on_change', 'allow_open_shift_claims', 'allow_swaps', 'allow_availability', 'minors_enabled'] as $key) {
            $store[$key] = (bool) ($validated[$key] ?? false);
        }

        foreach (['overtime_pay_code', 'extra_time_pay_code'] as $key) {
            $store[$key] = PlanningSettings::code($validated, $key);
        }

        $store['overtime_period_start'] = filled($validated['overtime_period_start'] ?? null) ? (string) $validated['overtime_period_start'] : null;
        $store['comp_time_factor'] = rtrim(rtrim(number_format((float) str_replace(',', '.', (string) $validated['comp_time_factor']), 2, '.', ''), '0'), '.');

        foreach (['working_time_rules' => PlanningSettings::WORKING_TIME_RULES, 'minors_rules' => PlanningSettings::MINORS_RULES] as $set => $defaults) {
            foreach ($defaults as $key => $default) {
                $value = $validated[$set][$key]['value'] ?? $default['value'];
                $store[$set][$key] = [
                    'value' => $default['unit'] === 'time' ? (string) $value : (int) $value,
                    'enabled' => (bool) ($validated[$set][$key]['enabled'] ?? false),
                ];
            }
        }

        $modules->updateSettings(PlanningModule::KEY, $company, $this->user(), $store);
        $this->settings = PlanningSettings::resolve($modules->settings(PlanningModule::KEY, $company));
        $this->toast(__('Inställningarna för planering är sparade.'));
    }

    public function render(): View
    {
        $company = Company::query()->findOrFail($this->companyId);

        return view('planning::settings-form', [
            'canAttest' => $this->user()->hasTeamPermission($company, 'planning:attest'),
            'labels' => self::labels(),
            'payCodes' => self::payCodes(),
        ]);
    }

    /**
     * Pay codes time beyond the plan can be sent on: additions counted in
     * hours (overtime, the agreement's overtime codes). Code => name.
     *
     * @return array<string, string>
     */
    private static function payCodes(): array
    {
        return PayCode::query()->where('unit', 'HOURS')->where('category', 'ADDITION')->where('is_active', true)->where('code', '<>', CompTimeLedger::PAY_CODE)->orderBy('code')->pluck('name_sv', 'code')->all();
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            'daily_rest' => __('Dygnsvila (minuter)'),
            'weekly_rest' => __('Veckovila (minuter)'),
            'max_without_break' => __('Längsta arbete utan rast (minuter)'),
            'ordinary_week' => __('Ordinarie arbetstid per vecka (timmar)'),
            'total_week' => __('Sammanlagd arbetstid per vecka (timmar)'),
            'overtime_four_weeks' => __('Allmän övertid på fyra veckor (timmar)'),
            'extra_time_week' => __('Mertid över anställningens veckoarbetstid'),
            'overtime_year' => __('Allmän övertid per kalenderår (timmar)'),
            'extra_time_year' => __('Allmän mertid per kalenderår (timmar)'),
            'under_16_max_day' => __('Under 16 år: högst per dag (minuter)'),
            'under_16_max_week' => __('Under 16 år: högst per vecka (timmar)'),
            'under_16_night_from' => __('Under 16 år: inte arbete från'),
            'under_16_night_to' => __('Under 16 år: inte arbete till'),
            'under_18_max_day' => __('Under 18 år: högst per dag (minuter)'),
            'under_18_max_week' => __('Under 18 år: högst per vecka (timmar)'),
            'under_18_night_from' => __('Under 18 år: inte arbete från'),
            'under_18_night_to' => __('Under 18 år: inte arbete till'),
            'under_18_daily_rest' => __('Under 18 år: dygnsvila (minuter)'),
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
