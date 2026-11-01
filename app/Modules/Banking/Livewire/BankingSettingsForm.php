<?php

declare(strict_types=1);

namespace App\Modules\Banking\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Banking\BankingModule;
use App\Modules\Banking\Models\BankConnection;
use App\Modules\Banking\Models\BankFeedAccount;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * NONOX Banking's settings under Moduler: how often the bank is read, and
 * whether and when to remind about consents that run out. The connected
 * banks and the account mapping are listed here and managed under Bank.
 */
class BankingSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'banking.settings-form';

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'banking:manage'), 403);

        $this->companyId = $company->id;
        $this->settings = [...BankingModule::DEFAULTS, ...app(ModuleManager::class)->settings(BankingModule::KEY, $company)];
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'banking:manage'), 403);

        $rules = ['settings.reconsent_reminders' => ['required', 'boolean']];

        foreach (BankingModule::LIMITS as $key => [$min, $max]) {
            $rules["settings.{$key}"] = ['required', 'integer', "between:{$min},{$max}"];
        }

        $validated = Validator::make(['settings' => $this->settings], $rules, [], [
            'settings.fetches_per_day' => __('Hämtningar per dag'),
            'settings.reconsent_reminder_days' => __('Påminn så här många dagar före'),
        ])->validate();

        $modules->updateSettings(BankingModule::KEY, $company, $this->user(), [
            'fetches_per_day' => (int) $validated['settings']['fetches_per_day'],
            'reconsent_reminders' => (bool) $validated['settings']['reconsent_reminders'],
            'reconsent_reminder_days' => (int) $validated['settings']['reconsent_reminder_days'],
        ]);

        $this->toast(__('Inställningarna för Bank är sparade.'));
    }

    public function render(): View
    {
        $company = Company::query()->findOrFail($this->companyId);

        return view('banking::settings-form', CurrentCompany::run($company, fn (): array => [
            'connections' => BankConnection::query()->orderByDesc('connected_at')->get(['id', 'institution_name', 'status', 'valid_until']),
            'accounts' => BankFeedAccount::query()->where('is_active', true)->with('ledgerAccount:id,number,name')->orderBy('id')->get(),
        ]));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
