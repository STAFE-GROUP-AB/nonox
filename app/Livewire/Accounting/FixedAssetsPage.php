<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Assets\FixedAssets;
use App\Domain\Assets\AcquisitionAccounts;
use App\Domain\Assets\AssetCategory;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Anläggningar: the fixed asset register, the month's
 * depreciation and disposals.
 */
class FixedAssetsPage extends Component
{
    use ShowsToasts;

    /** @var array<string, string> */
    public array $form = [];

    public ?int $editing = null;

    public bool $creating = false;

    #[Url(as: 'manad', except: '')]
    public string $month = '';

    public ?int $showing = null;

    public ?int $disposing = null;

    /** @var array<string, string> */
    public array $disposal = ['date' => '', 'price' => '', 'account' => '1930'];

    /** @var array{accounts: string, threshold: string} the acquisition accounts and the threshold for assets created from purchases */
    public array $settings = ['accounts' => '', 'threshold' => ''];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'fiscal-years:manage'), 403);

        $this->month = $this->month !== '' ? $this->month : now()->subMonthNoOverflow()->format('Y-m');
        $this->fillSettings($this->company());
    }

    public function saveSettings(FixedAssets $assets): void
    {
        $company = $assets->saveSettings($this->user(), $this->company(), $this->settings['accounts'], $this->settings['threshold']);

        $this->fillSettings($company);
        $this->toast(__('Inställningarna för inköp av anläggningstillgångar är sparade.'));
    }

    private function fillSettings(Company $company): void
    {
        $this->settings = [
            'accounts' => AcquisitionAccounts::for($company)->format(),
            'threshold' => $company->asset_small_value_threshold_ore === null ? '' : Money::ofOre($company->asset_small_value_threshold_ore)->format(withCurrency: false),
        ];
    }

    public function create(): void
    {
        $category = AssetCategory::Equipment;
        $this->form = [
            'name' => '', 'category' => $category->value, 'acquired_on' => now()->toDateString(),
            'acquisition' => '', 'residual' => '', 'opening_accumulated' => '', 'life_months' => (string) $category->suggestedLifeMonths(),
            'depreciation_start' => now()->startOfMonth()->toDateString(), 'cost_center' => '', 'notes' => '',
            ...array_map('strval', array_combine(['asset_account', 'accumulated_account', 'expense_account'], $category->accounts())),
        ];
        $this->creating = true;
        $this->editing = null;
        $this->resetErrorBag();
    }

    public function updatedFormCategory(string $value): void
    {
        $category = AssetCategory::tryFrom($value) ?? AssetCategory::Equipment;
        $accounts = $category->accounts();
        $this->form['asset_account'] = (string) $accounts['asset'];
        $this->form['accumulated_account'] = (string) $accounts['accumulated'];
        $this->form['expense_account'] = (string) $accounts['expense'];
        $this->form['life_months'] = (string) $category->suggestedLifeMonths();
    }

    public function edit(int $assetId): void
    {
        $asset = FixedAsset::query()->findOrFail($assetId);
        $this->form = [
            'name' => $asset->name, 'category' => $asset->category->value, 'acquired_on' => $asset->acquired_on->toDateString(),
            'acquisition' => $asset->acquisition_ore->format(withCurrency: false), 'residual' => $asset->residual_ore->format(withCurrency: false),
            'opening_accumulated' => $asset->opening_accumulated_ore->format(withCurrency: false), 'life_months' => (string) $asset->useful_life_months,
            'depreciation_start' => $asset->depreciation_start->toDateString(), 'asset_account' => (string) $asset->asset_account,
            'accumulated_account' => (string) $asset->accumulated_account, 'expense_account' => (string) $asset->expense_account,
            'cost_center' => (string) ($asset->costCenter->code ?? ''), 'notes' => (string) $asset->notes,
        ];
        $this->editing = $asset->id;
        $this->creating = true;
        $this->resetErrorBag();
    }

    public function save(FixedAssets $assets): void
    {
        $asset = $assets->save($this->user(), $this->company(), $this->form, $this->editing === null ? null : FixedAsset::query()->findOrFail($this->editing));

        $this->reset('creating', 'editing', 'form');
        $this->toast(__(':name är sparad i anläggningsregistret.', ['name' => $asset->name]));
    }

    public function depreciate(FixedAssets $assets): void
    {
        try {
            $voucher = $assets->depreciate($this->user(), $this->company(), $this->month);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->toast($voucher === null
            ? __('Det finns inget att skriva av för månaden.')
            : __('Avskrivningarna för :month är bokförda som :voucher.', ['month' => $this->month, 'voucher' => $voucher->reference()]));
    }

    public function startDisposal(int $assetId): void
    {
        $this->disposing = $assetId;
        $this->disposal = ['date' => now()->toDateString(), 'price' => '', 'account' => '1930'];
        $this->resetErrorBag();
    }

    public function dispose(FixedAssets $assets): void
    {
        if ($this->disposing === null) {
            return;
        }

        try {
            $asset = $assets->dispose($this->user(), FixedAsset::query()->findOrFail($this->disposing), $this->disposal['date'], $this->disposal['price'], (int) $this->disposal['account']);
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->reset('disposing');
        $this->toast(__(':name är bortbokad ur registret.', ['name' => $asset->name]));
    }

    public function addAccounts(FixedAssets $assets): void
    {
        $added = $assets->addAccounts($this->user(), $this->company());
        $this->toast($added === [] ? __('Alla konton finns redan.') : __('Kontona :accounts är tillagda i kontoplanen.', ['accounts' => implode(', ', $added)]));
    }

    public function render(FixedAssets $assets): View
    {
        $list = FixedAsset::query()->withSum('depreciations', 'amount_ore')->with(['acquisitionVoucher.series', 'supplierInvoice'])->orderBy('status')->orderBy('number')->get();
        $preview = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? $assets->preview($this->company(), $this->month) : [];
        $months = array_map(fn (int $i): string => now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'), range(0, 12));

        return view('livewire.accounting.fixed-assets', [
            'assets' => $list,
            'preview' => $preview,
            'months' => $months,
            'categories' => AssetCategory::cases(),
            'costCenters' => CostCenter::query()->orderBy('code')->get(['code', 'name']),
            'missingAccounts' => FixedAssets::missingAccounts($this->company()),
            'shown' => $this->showing === null ? null : $list->firstWhere('id', $this->showing),
            'totals' => [
                'acquisition' => $list->where('status', FixedAsset::ACTIVE)->sum(fn (FixedAsset $asset): int => $asset->acquisition_ore->ore),
                'book_value' => $list->where('status', FixedAsset::ACTIVE)->sum(fn (FixedAsset $asset): int => $asset->bookValueOre()),
            ],
            'today' => CarbonImmutable::today(),
        ]);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
