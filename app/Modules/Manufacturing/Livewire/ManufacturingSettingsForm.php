<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Manufacturing\ManufacturingModule;
use App\Modules\Manufacturing\ManufacturingSettings;
use App\Modules\ModuleManager;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Tillverkning's settings, on Tillverkning → Inställningar and under
 * Moduler (ADR-071 §6, §8): the overhead added to material and labour in
 * the costing, off by default; whether it is also part of the stock value
 * of finished goods and work in progress, off by default; and the account
 * pair for the work in progress at year end (manufacturing:manage).
 */
class ManufacturingSettingsForm extends Component
{
    use ShowsToasts;

    public const string NAME = 'manufacturing.settings-form';

    #[Locked]
    public int $companyId;

    public string $overhead = '';

    public bool $overheadInStock = false;

    public string $wipStockAccount = '';

    public string $wipChangeAccount = '';

    public function mount(Company $company): void
    {
        abort_unless($this->user()->hasTeamPermission($company, 'manufacturing:manage'), 403);

        $this->companyId = $company->id;
        $bp = ManufacturingSettings::overheadBp($company);
        $this->overhead = $bp === 0 ? '' : str_replace('.', ',', rtrim(rtrim((string) BigDecimal::of($bp)->dividedBy(100, 2), '0'), '.'));
        $this->overheadInStock = ManufacturingSettings::overheadInStockValue($company);
        [$stock, $change] = ManufacturingSettings::wipAccounts($company);
        $this->wipStockAccount = (string) $stock;
        $this->wipChangeAccount = (string) $change;
    }

    public function save(ModuleManager $modules): void
    {
        $company = Company::query()->findOrFail($this->companyId);
        abort_unless($this->user()->hasTeamPermission($company, 'manufacturing:manage'), 403);

        try {
            $bp = self::percent($this->overhead);
            $stock = self::account($this->wipStockAccount, 'wipStockAccount', InventorySettings::STOCK_ACCOUNTS);
            $change = self::account($this->wipChangeAccount, 'wipChangeAccount', InventorySettings::CHANGE_ACCOUNTS);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $modules->updateSettings(ManufacturingModule::KEY, $company, $this->user(), [
            'overhead_bp' => $bp,
            'overhead_in_stock_value' => $this->overheadInStock,
            'wip_stock_account' => $stock,
            'wip_change_account' => $change,
        ]);
        $this->resetErrorBag();
        $this->toast(__('Inställningarna för tillverkning är sparade.'));
    }

    public function render(): View
    {
        return view('manufacturing::livewire.settings-form');
    }

    private static function percent(string $text): ?int
    {
        $text = str_replace([' ', ',', '%'], ['', '.', ''], trim($text));

        if ($text === '' || $text === '0') {
            return null;
        }

        try {
            $bp = BigDecimal::of($text)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
        } catch (MathException) {
            throw ValidationException::withMessages(['overhead' => __('Skriv pålägget i procent, till exempel 15.')]);
        }

        if ($bp < 0 || $bp > 100_000) {
            throw ValidationException::withMessages(['overhead' => __('Pålägget ska vara mellan 0 och 1 000 procent.')]);
        }

        return $bp === 0 ? null : $bp;
    }

    /** @param  array{0: int, 1: int}  $range */
    private static function account(string $text, string $field, array $range): int
    {
        $text = trim($text);

        if (preg_match('/^\d{4}$/', $text) !== 1 || (int) $text < $range[0] || (int) $text > $range[1]) {
            throw ValidationException::withMessages([$field => __('Välj ett konto mellan :from och :to.', ['from' => $range[0], 'to' => $range[1]])]);
        }

        return (int) $text;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
