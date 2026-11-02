<?php

declare(strict_types=1);

namespace App\Modules\Pos\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Pos\Domain\PaymentMethod;
use App\Modules\Pos\PosModule;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Kassakopplingar's settings under Moduler (ADR-042): the accounts of the
 * daily voucher, per VAT rate and per payment method, and whether the
 * draft is proposed automatically every morning.
 */
class PosSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'pos.settings-form';

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'settings:manage'), 403);

        $this->companyId = $company->id;
        $this->settings = array_map(fn (mixed $value): mixed => is_int($value) ? (string) $value : $value, [...PosModule::DEFAULTS, ...app(ModuleManager::class)->settings(PosModule::KEY, $company)]);
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'settings:manage'), 403);

        $rules = ['settings.auto_daily_draft' => ['required', 'boolean']];

        foreach (array_keys(PosModule::DEFAULTS) as $key) {
            if (str_starts_with($key, 'account_')) {
                $rules["settings.{$key}"] = ['required', 'integer', 'between:1000,9999'];
            }
        }

        $validated = Validator::make(['settings' => $this->settings], $rules, [], ['settings.*' => __('konto')])->validate();
        $chart = CurrentCompany::run($company, fn (): array => Account::query()->where('is_active', true)->pluck('number')->map(fn (mixed $number): int => (int) $number)->all());
        $values = ['auto_daily_draft' => (bool) $validated['settings']['auto_daily_draft']];

        foreach (array_keys($rules) as $rule) {
            $key = substr($rule, strlen('settings.'));

            if ($key === 'auto_daily_draft') {
                continue;
            }

            $number = (int) $validated['settings'][$key];

            if (! in_array($number, $chart, true)) {
                $this->addError("settings.{$key}", __('Konto :number finns inte i kontoplanen.', ['number' => $number]));
                $this->toast(__('Konto :number finns inte i kontoplanen.', ['number' => $number]), 'error');

                throw ValidationException::withMessages(["settings.{$key}" => __('Konto :number finns inte i kontoplanen.', ['number' => $number])]);
            }

            $values[$key] = $number;
        }

        $modules->updateSettings(PosModule::KEY, $company, $this->user(), $values);

        $this->toast(__('Inställningarna för Kassakopplingar är sparade.'));
    }

    public function render(): View
    {
        return view('pos::settings-form', [
            'rates' => [2500, 1200, 600, 0],
            'methods' => PaymentMethod::cases(),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
