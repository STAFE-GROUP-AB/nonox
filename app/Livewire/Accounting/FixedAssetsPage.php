<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Actions\Assets\FixedAssets;
use App\Domain\Assets\AcquisitionAccounts;
use App\Domain\Assets\AssetCategory;
use App\Domain\Assets\ComponentSplit;
use App\Domain\Company\ReportingFramework;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Anläggningar: the fixed asset register, the month's
 * depreciation and disposals, and Komponenter: splitting an asset into
 * components in a K3 year (ADR-059 A.2.4, S1-P4), and Lägg till inköp:
 * a posted purchase added to an existing asset (ADR-010).
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

    /** The asset being split into components. */
    public ?int $splitting = null;

    /** The first day of the month the split applies from. */
    public string $splitDate = '';

    /** @var list<array<string, string>> each: name, kind, cost, accumulated, life_months, residual, asset_account */
    public array $components = [];

    /** The person has typed a residual value: the pre-fill stops, so it never overwrites one. */
    public bool $residualsTouched = false;

    /** The split is shown with its consequences before it is made. */
    public bool $confirmingSplit = false;

    /** The asset a posted purchase is being added to. */
    public ?int $adding = null;

    /** The voucher row chosen to add. */
    public string $additionRow = '';

    /** The addition is shown with its consequences before it is made. */
    public bool $confirmingAddition = false;

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
            ...array_map(fn (?int $account): string => (string) $account, array_combine(['asset_account', 'accumulated_account', 'expense_account'], $category->accounts())),
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

    /**
     * Opens Komponenter for an asset: from the month after the last booked
     * depreciation, with the building template of K3 17.4 (land, stomme, tak,
     * fasad, installationer) for a building and two empty rows otherwise.
     */
    public function startSplit(int $assetId): void
    {
        $asset = FixedAsset::query()->withSum('depreciations', 'amount_ore')->findOrFail($assetId);
        $lastBooked = $asset->depreciations()->max('period');
        $from = is_string($lastBooked)
            ? CarbonImmutable::parse("{$lastBooked}-01")->addMonthNoOverflow()
            : CarbonImmutable::today()->startOfMonth();
        $remaining = (string) FixedAssets::remainingMonths($asset, $from);
        $part = fn (string $name): array => ['name' => $name, 'kind' => FixedAssets::COMPONENT_PART, 'cost' => '', 'accumulated' => '', 'life_months' => $remaining, 'total_life_months' => '', 'residual' => '', 'asset_account' => (string) $asset->asset_account];

        $this->components = $asset->category === AssetCategory::Buildings
            ? [
                ['name' => __('Mark'), 'kind' => FixedAssets::COMPONENT_LAND, 'cost' => '', 'accumulated' => '', 'life_months' => '', 'total_life_months' => '', 'residual' => '', 'asset_account' => (string) AssetCategory::Land->accounts()['asset']],
                $part(__('Stomme')),
                $part(__('Tak')),
                $part(__('Fasad')),
                $part(__('Stammar och installationer')),
            ]
            : [$part(''), $part('')];
        $this->splitting = $asset->id;
        $this->splitDate = $from->toDateString();
        $this->confirmingSplit = false;
        $this->residualsTouched = false;
        $this->reset('creating', 'editing', 'disposing');
        $this->resetErrorBag();
    }

    public function addComponent(): void
    {
        if ($this->splitting === null || count($this->components) >= 20) {
            return;
        }

        $asset = FixedAsset::query()->findOrFail($this->splitting);
        $this->components[] = ['name' => '', 'kind' => FixedAssets::COMPONENT_PART, 'cost' => '', 'accumulated' => '', 'life_months' => '', 'total_life_months' => '', 'residual' => '', 'asset_account' => (string) $asset->asset_account];
    }

    /**
     * A changed cost or kind spreads the asset's residual value over the parts
     * again, until the person types a residual value of their own.
     */
    public function updatedComponents(mixed $value, ?string $key = null): void
    {
        if ($key !== null && str_ends_with($key, '.residual')) {
            $this->residualsTouched = true;

            return;
        }

        if ($key === null || str_ends_with($key, '.cost') || str_ends_with($key, '.kind')) {
            $this->prefillResiduals();
        }
    }

    /**
     * The asset's residual value divided over the depreciable parts in
     * proportion to their cost, by the largest remainder (ComponentSplit), so
     * the parts add up to the asset's. Land gets none. Only once every part
     * has a cost that can be read; the person may change the result.
     */
    private function prefillResiduals(): void
    {
        if ($this->splitting === null || $this->residualsTouched) {
            return;
        }

        $residual = FixedAsset::query()->whereKey($this->splitting)->toBase()->value('residual_ore');

        if (! is_numeric($residual) || (int) $residual <= 0) {
            return;
        }

        $weights = [];

        foreach ($this->components as $index => $component) {
            if (($component['kind'] ?? '') === FixedAssets::COMPONENT_LAND) {
                $this->components[$index]['residual'] = '';

                continue;
            }

            try {
                $weights[$index] = Money::parse((string) ($component['cost'] ?? ''))->ore;
            } catch (InvalidArgumentException) {
                return;
            }

            if ($weights[$index] <= 0) {
                return;
            }
        }

        foreach (ComponentSplit::largestRemainder((int) $residual, $weights) as $index => $share) {
            $this->components[$index]['residual'] = Money::ofOre($share)->format(withCurrency: false);
        }
    }

    public function removeComponent(int $index): void
    {
        array_splice($this->components, $index, 1);
        $this->confirmingSplit = false;
    }

    public function reviewSplit(): void
    {
        $this->resetErrorBag();
        $this->confirmingSplit = $this->splitting !== null;
    }

    public function cancelSplit(): void
    {
        $this->reset('splitting', 'splitDate', 'components', 'confirmingSplit', 'residualsTouched');
        $this->resetErrorBag();
    }

    public function split(FixedAssets $assets): void
    {
        if ($this->splitting === null) {
            return;
        }

        $this->confirmingSplit = false;

        try {
            $asset = $assets->split($this->user(), $this->company(), $this->splitting, $this->splitDate, $this->components);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->cancelSplit();
        $this->toast(__(':name är uppdelad i :count komponenter från :date.', ['name' => $asset->name, 'count' => $asset->components->count(), 'date' => $asset->components->first()?->depreciation_start->toDateString()]));
    }

    /** Opens Lägg till inköp under an asset: a posted purchase on its account becomes part of it. */
    public function startAddition(int $assetId): void
    {
        $asset = FixedAsset::query()->findOrFail($assetId);

        if ($asset->status !== FixedAsset::ACTIVE) {
            $this->toast($asset->isSplit()
                ? __('Tillgången är uppdelad i komponenter. Lägg till inköpet i den komponent det hör till.')
                : __('Tillgången är avyttrad, och inget kan läggas till i den.'), 'error');

            return;
        }

        $this->adding = $asset->id;
        $this->additionRow = '';
        $this->confirmingAddition = false;
        $this->reset('creating', 'editing', 'disposing', 'splitting');
        $this->resetErrorBag();
    }

    public function reviewAddition(): void
    {
        $this->resetErrorBag();

        if ($this->adding === null || $this->additionRow === '') {
            $this->addError('addition', __('Välj ett inköp att lägga till.'));

            return;
        }

        $this->confirmingAddition = true;
    }

    public function cancelAddition(): void
    {
        $this->reset('adding', 'additionRow', 'confirmingAddition');
        $this->resetErrorBag();
    }

    public function addPurchase(FixedAssets $assets): void
    {
        if ($this->adding === null || ! ctype_digit($this->additionRow)) {
            return;
        }

        $this->confirmingAddition = false;

        try {
            $addition = $assets->addPurchase($this->user(), $this->company(), $this->adding, (int) $this->additionRow);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $asset = FixedAsset::query()->findOrFail($addition->fixed_asset_id);
        $this->cancelAddition();
        $this->toast(__(':amount är tillagt i :name. Anskaffningsvärdet är nu :total.', ['amount' => $addition->amount_ore->format(), 'name' => $asset->name, 'total' => $asset->acquisition_ore->format()]));
    }

    public function addAccounts(FixedAssets $assets): void
    {
        $added = $assets->addAccounts($this->user(), $this->company());
        $this->toast($added === [] ? __('Alla konton finns redan.') : __('Kontona :accounts är tillagda i kontoplanen.', ['accounts' => implode(', ', $added)]));
    }

    public function render(FixedAssets $assets): View
    {
        $list = FixedAsset::query()->withSum('depreciations', 'amount_ore')
            ->with(['acquisitionVoucher.series', 'supplierInvoice', 'additions.voucher.series', 'additions.supplierInvoice'])
            ->orderBy('status')->orderBy('number')->get();
        $adding = $this->adding === null ? null : $list->firstWhere('id', $this->adding);
        $candidates = $adding === null ? collect() : FixedAssets::additionCandidates($this->company(), $adding);
        // Components are listed under their asset, from the same query.
        $children = $list->whereNotNull('parent_id')->groupBy('parent_id');
        $split = $this->splitting === null ? null : $list->firstWhere('id', $this->splitting);
        $preview = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? $assets->preview($this->company(), $this->month) : [];
        $months = array_map(fn (int $i): string => now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m'), range(0, 12));

        return view('livewire.accounting.fixed-assets', [
            'assets' => $list->whereNull('parent_id')->values(),
            'children' => $children,
            'split' => $split,
            'splitTotal' => $this->splitTotal(),
            'reviewRows' => $this->confirmingSplit ? $this->reviewRows() : [],
            'k3' => $k3 = $this->company()->reporting_framework === ReportingFramework::K3,
            'unsplitBuildings' => $k3
                ? $list->filter(fn (FixedAsset $asset): bool => $asset->category === AssetCategory::Buildings && $asset->status === FixedAsset::ACTIVE && ! $asset->isComponent())->values()
                : collect(),
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
            'addingAsset' => $adding,
            'editingHasAdditions' => $this->editing !== null && ($list->firstWhere('id', $this->editing)?->additions->isNotEmpty() ?? false),
            'candidates' => $candidates,
            'additionReview' => $adding !== null && $this->confirmingAddition ? $this->additionReview($adding, $candidates) : null,
        ]);
    }

    /**
     * What adding the chosen purchase does: the amount, the new acquisition
     * value, and from which month and over how many months it is written off.
     *
     * @param  Collection<int, VoucherRow>  $candidates
     * @return array{amount: int, total: int, from: string, life: int, depreciates: bool, manual: bool}|null
     */
    private function additionReview(FixedAsset $asset, Collection $candidates): ?array
    {
        $row = $candidates->firstWhere('id', (int) $this->additionRow);

        if (! $row instanceof VoucherRow || ! $row->voucher instanceof Voucher) {
            return null;
        }

        $booked = $asset->depreciations()->max('period');
        $terms = $asset->additionTerms($row->voucher->voucher_date, is_string($booked) ? $booked : null);

        return [
            'amount' => $row->debit_ore->ore,
            'total' => $asset->acquisition_ore->ore + $row->debit_ore->ore,
            'from' => $terms['from']->format('Y-m'),
            'life' => $terms['life'],
            'depreciates' => $asset->depreciates(),
            'manual' => $asset->acquisition_voucher_id === null && $asset->supplier_invoice_id === null,
        ];
    }

    /**
     * The split as it will be made, for the review: each component's cost,
     * residual value and remaining life as entered.
     *
     * @return list<array{name: string, cost: string, residual: string, life: string}>
     */
    private function reviewRows(): array
    {
        $amount = function (string $value): string {
            try {
                return trim($value) === '' ? Money::ofOre(0)->format() : Money::parse($value)->format();
            } catch (InvalidArgumentException) {
                return $value;
            }
        };

        return array_map(fn (array $component): array => [
            'name' => (string) ($component['name'] ?? ''),
            'cost' => $amount((string) ($component['cost'] ?? '')),
            'residual' => ($component['kind'] ?? '') === FixedAssets::COMPONENT_LAND ? '–' : $amount((string) ($component['residual'] ?? '')),
            'life' => ($component['kind'] ?? '') === FixedAssets::COMPONENT_LAND ? __('Skrivs inte av') : (string) ($component['life_months'] ?? ''),
        ], $this->components);
    }

    /** The sum of the component costs entered so far, or null while one cannot be read. */
    private function splitTotal(): ?int
    {
        $total = 0;

        foreach ($this->components as $component) {
            try {
                $total += trim((string) ($component['cost'] ?? '')) === '' ? 0 : Money::parse((string) $component['cost'])->ore;
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return $total;
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
