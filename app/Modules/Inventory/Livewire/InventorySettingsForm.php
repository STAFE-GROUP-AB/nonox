<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Domain\StockClass;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\InventorySettings;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lager's settings, on Lager → Inställningar and under Moduler: the
 * valuation method, whether stock may go below zero, the default stock
 * class and the account pair per class the year-end stock change is booked
 * on (ADR-073 §9), and for K2 companies the 97 % rule.
 */
class InventorySettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'inventory.settings-form';

    #[Locked]
    public int $companyId;

    /** @var array<string, mixed> */
    public array $settings = [];

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'inventory:settings'), 403);

        $this->companyId = $company->id;
        $this->settings = InventorySettings::for($company);
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'inventory:settings'), 403);

        [$stockFrom, $stockTo] = InventorySettings::STOCK_ACCOUNTS;
        [$changeFrom, $changeTo] = InventorySettings::CHANGE_ACCOUNTS;

        $validated = Validator::make(['settings' => $this->settings], [
            'settings.valuation_method' => ['required', Rule::enum(ValuationMethod::class)],
            'settings.allow_negative_stock' => ['boolean'],
            'settings.stock_account' => ['required', 'integer', "between:{$stockFrom},{$stockTo}"],
            'settings.change_account' => ['required', 'integer', "between:{$changeFrom},{$changeTo}"],
            'settings.default_stock_class' => ['required', Rule::enum(StockClass::class)],
            'settings.raw_material_stock_account' => ['required', 'integer', "between:{$stockFrom},{$stockTo}"],
            'settings.raw_material_change_account' => ['required', 'integer', "between:{$changeFrom},{$changeTo}"],
            'settings.rule_97' => ['boolean'],
        ], [
            'settings.stock_account.between' => __('Välj ett lagerkonto mellan :from och :to.', ['from' => $stockFrom, 'to' => $stockTo]),
            'settings.change_account.between' => __('Välj ett konto för lagerförändring mellan :from och :to.', ['from' => $changeFrom, 'to' => $changeTo]),
            'settings.raw_material_stock_account.between' => __('Välj ett lagerkonto mellan :from och :to.', ['from' => $stockFrom, 'to' => $stockTo]),
            'settings.raw_material_change_account.between' => __('Välj ett konto för lagerförändring mellan :from och :to.', ['from' => $changeFrom, 'to' => $changeTo]),
        ])->validate();

        $modules->updateSettings(InventoryModule::KEY, $company, $this->user(), [
            'valuation_method' => (string) $validated['settings']['valuation_method'],
            'allow_negative_stock' => (bool) ($validated['settings']['allow_negative_stock'] ?? false),
            'stock_account' => (int) $validated['settings']['stock_account'],
            'change_account' => (int) $validated['settings']['change_account'],
            'default_stock_class' => (string) $validated['settings']['default_stock_class'],
            'raw_material_stock_account' => (int) $validated['settings']['raw_material_stock_account'],
            'raw_material_change_account' => (int) $validated['settings']['raw_material_change_account'],
            // Offered to K2 companies only (Lead decision O3); never on for another framework.
            'rule_97' => InventorySettings::offersRule97($company) && (bool) ($validated['settings']['rule_97'] ?? false),
        ]);

        $this->toast(__('Inställningarna för lagret är sparade.'));
    }

    public function render(): View
    {
        return view('inventory::livewire.settings-form', [
            'methods' => ValuationMethod::cases(),
            'classes' => StockClass::cases(),
            'k2' => InventorySettings::offersRule97(Company::query()->findOrFail($this->companyId)),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
