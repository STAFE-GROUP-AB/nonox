<?php

declare(strict_types=1);

namespace App\Actions\Assets;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Assets\AcquisitionAccounts;
use App\Domain\Assets\AssetCategory;
use App\Domain\Assets\ComponentSplit;
use App\Domain\Assets\ComponentSplitRefused;
use App\Domain\Assets\DepreciationBasis;
use App\Domain\Company\ReportingFramework;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The fixed asset register: registering assets, booking each month's
 * depreciation as one voucher, and booking a disposal. The register is part
 * of the year-end closing, so it needs fiscal-years:manage; the vouchers are
 * posted through BookVoucher and PostVoucher, with every posting rule.
 */
class FixedAssets
{
    public const string SERIES = 'A';

    public const int GAIN_ACCOUNT = 3973;

    public const int LOSS_ACCOUNT = 7973;

    /** The first key of the transaction-level advisory lock that numbers a company's new assets. */
    public const int NUMBERING_LOCK = 4201;

    /** The kinds of component in a split: a depreciable part, or land (never depreciated). */
    public const string COMPONENT_PART = 'part';

    public const string COMPONENT_LAND = 'land';

    public function __construct(private readonly BookVoucher $book) {}

    /**
     * @param  array<string, mixed>  $input  name, category, acquired_on, acquisition, residual, opening_accumulated, life_months, depreciation_start, asset_account, accumulated_account, expense_account, cost_center, notes
     */
    public function save(User $user, Company $company, array $input, ?FixedAsset $asset = null): FixedAsset
    {
        $this->authorize($user, $company);

        return $this->store($user, $company, $input, $asset);
    }

    /**
     * Creates the asset whose purchase is being booked, linked to the voucher
     * and row that book it (acquisition_voucher_id, acquisition_voucher_row_id)
     * or the supplier invoice. Only for AssetProposals, inside the posting or
     * registration transaction: the person who proposed the asset was
     * checked for fiscal-years:manage when the proposal was saved, so a
     * bookkeeper can post a draft an accountant prepared.
     *
     * @param  array<string, mixed>  $input  as for save()
     * @param  array{acquisition_voucher_id?: ?string, acquisition_voucher_row_id?: ?int, supplier_invoice_id?: ?string}  $links
     */
    public function createFromPurchase(User $user, Company $company, array $input, array $links): FixedAsset
    {
        return $this->store($user, $company, $input, null, $links);
    }

    /**
     * The company's settings for assets created from purchases: the
     * acquisition accounts ("1210, 1220-1227"; empty for the register's own)
     * and the amount under which no asset is proposed (empty for none).
     *
     * TODO(legal-verify): inventarier av mindre värde may be expensed at once
     * (IL 18 kap. 4 §); Skatteverket's limit is half a price base amount,
     * excluding VAT, and inventarier bought together count together. The
     * amount is the company's own setting and NONOX suggests none.
     */
    public function saveSettings(User $user, Company $company, string $accounts, string $threshold): Company
    {
        $this->authorize($user, $company);

        $ranges = AcquisitionAccounts::parse($accounts, 'acquisition_accounts');
        $thresholdOre = trim($threshold) === '' ? null : $this->money($threshold, 'small_value_threshold');

        if ($thresholdOre !== null && $thresholdOre < 0) {
            throw ValidationException::withMessages(['small_value_threshold' => __('Gränsen kan inte vara negativ.')]);
        }

        $company->forceFill([
            'asset_acquisition_accounts' => $ranges->isDefault() ? null : $ranges->ranges,
            'asset_small_value_threshold_ore' => $thresholdOre,
        ])->save();

        Audit::record('fixed_asset.settings_changed', $company, [
            'acquisition_accounts' => $ranges->format(),
            'small_value_threshold_ore' => $thresholdOre,
        ], $company, $user);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{acquisition_voucher_id?: ?string, acquisition_voucher_row_id?: ?int, supplier_invoice_id?: ?string}  $links
     */
    private function store(User $user, Company $company, array $input, ?FixedAsset $asset, array $links = []): FixedAsset
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(AssetCategory::class)],
            'acquired_on' => ['required', 'date_format:Y-m-d'],
            'acquisition' => ['required', 'string'],
            'residual' => ['nullable', 'string'],
            'opening_accumulated' => ['nullable', 'string'],
            'life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'depreciation_start' => ['required', 'date_format:Y-m-d'],
            'asset_account' => ['required', 'integer', 'between:1000,1999'],
            'accumulated_account' => ['nullable', 'integer', 'between:1000,1999'],
            'expense_account' => ['nullable', 'integer', 'between:7000,7999'],
            'cost_center' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'name' => __('benämning'),
            'acquired_on' => __('anskaffningsdag'),
            'acquisition' => __('anskaffningsvärde'),
            'life_months' => __('nyttjandeperiod'),
            'depreciation_start' => __('första avskrivningsmånad'),
        ])->validate();

        $acquisition = $this->money((string) $validated['acquisition'], 'acquisition');
        $residual = $this->money((string) ($validated['residual'] ?? ''), 'residual');
        $opening = $this->money((string) ($validated['opening_accumulated'] ?? ''), 'opening_accumulated');

        if ($acquisition <= 0) {
            throw ValidationException::withMessages(['acquisition' => __('Anskaffningsvärdet ska vara större än noll.')]);
        }

        if ($residual < 0 || $residual >= $acquisition) {
            throw ValidationException::withMessages(['residual' => __('Restvärdet ska vara mindre än anskaffningsvärdet.')]);
        }

        if ($opening < 0 || $opening > $acquisition - $residual) {
            throw ValidationException::withMessages(['opening_accumulated' => __('Gjorda avskrivningar kan inte vara större än det som ska skrivas av.')]);
        }

        $category = AssetCategory::from((string) $validated['category']);
        $life = (int) ($validated['life_months'] ?? 0);

        if ($category->depreciates()) {
            if ($life < 1 || $life > $category->maxLifeMonths()) {
                throw ValidationException::withMessages(['life_months' => __('Nyttjandeperioden ska vara mellan 1 och :max månader.', ['max' => $category->maxLifeMonths()])]);
            }

            foreach (['accumulated_account', 'expense_account'] as $field) {
                if (blank($validated[$field] ?? null)) {
                    throw ValidationException::withMessages([$field => __('Ange kontot.')]);
                }
            }
        } elseif ($opening !== 0) {
            // Land is never depreciated (K3 17.13 comment, S1-R3.2).
            throw ValidationException::withMessages(['opening_accumulated' => __('Mark skrivs inte av och kan inte ha gjorda avskrivningar.')]);
        }

        return CurrentCompany::run($company, fn (): FixedAsset => DB::transaction(function () use ($user, $company, $validated, $acquisition, $residual, $opening, $category, $life, $asset, $links): FixedAsset {
            // A split asset and its components carry the split's figures; the depreciated plan, too, is fixed.
            if ($asset !== null && ($asset->isSplit() || $asset->isComponent() || $asset->depreciations()->exists())) {
                // The plan behind booked months cannot change; only the description may.
                $asset->forceFill(['name' => $validated['name'], 'notes' => $validated['notes'] ?? null])->save();
                Audit::record('fixed_asset.updated', $asset, ['name' => $asset->name], $company, $user);

                return $asset;
            }

            $costCenter = blank($validated['cost_center'] ?? null) ? null : CostCenter::query()->where('code', $validated['cost_center'])->first()
                ?? throw ValidationException::withMessages(['cost_center' => __('Kostnadsstället finns inte.')]);

            $attributes = [
                'name' => $validated['name'],
                'category' => $validated['category'],
                'acquired_on' => $validated['acquired_on'],
                'acquisition_ore' => $acquisition,
                'residual_ore' => $residual,
                'opening_accumulated_ore' => $opening,
                'useful_life_months' => $category->depreciates() ? $life : 0,
                'depreciation_start' => CarbonImmutable::parse((string) $validated['depreciation_start'])->startOfMonth()->toDateString(),
                'asset_account' => (int) $validated['asset_account'],
                'accumulated_account' => $category->depreciates() ? (int) $validated['accumulated_account'] : null,
                'expense_account' => $category->depreciates() ? (int) $validated['expense_account'] : null,
                'cost_center_id' => $costCenter?->id,
                'notes' => $validated['notes'] ?? null,
            ];

            if ($asset === null) {
                // A lock of its own per company, so two new assets never get the same number. Not the
                // company row: a posting holds that with FOR KEY SHARE, and two postings each creating
                // an asset would then deadlock waiting to upgrade it.
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::NUMBERING_LOCK, $company->id]);
                $asset = FixedAsset::create([
                    ...$attributes,
                    ...$links,
                    'number' => (int) FixedAsset::query()->max('number') + 1,
                    'status' => FixedAsset::ACTIVE,
                    'created_by' => $user->id,
                ]);
                Audit::record('fixed_asset.created', $asset, array_filter([
                    'name' => $asset->name,
                    'acquisition_ore' => $acquisition,
                    'acquisition_voucher_id' => $links['acquisition_voucher_id'] ?? null,
                    'acquisition_voucher_row_id' => $links['acquisition_voucher_row_id'] ?? null,
                    'supplier_invoice_id' => $links['supplier_invoice_id'] ?? null,
                ], fn (mixed $value): bool => $value !== null), $company, $user);

                return $asset;
            }

            $asset->forceFill($attributes)->save();
            Audit::record('fixed_asset.updated', $asset, ['name' => $asset->name], $company, $user);

            return $asset;
        }));
    }

    /**
     * What a month's depreciation would book, per active asset.
     *
     * @return list<array{asset: FixedAsset, amount: int}>
     */
    public function preview(Company $company, string $month): array
    {
        return CurrentCompany::run($company, function () use ($month): array {
            $booked = FixedAssetDepreciation::query()->where('period', $month)->pluck('fixed_asset_id')->all();
            $assets = FixedAsset::query()->where('status', FixedAsset::ACTIVE)
                ->where('category', '!=', AssetCategory::Land->value)
                ->whereDate('depreciation_start', '<=', CarbonImmutable::parse("{$month}-01")->endOfMonth()->toDateString())
                ->whereNotIn('id', $booked)
                ->withSum('depreciations', 'amount_ore')
                ->orderBy('number')
                ->get();

            return array_values(array_filter($assets->map(fn (FixedAsset $asset): array => [
                'asset' => $asset,
                'amount' => $asset->plan()->amountFor($month, $asset->accumulatedOre()),
            ])->all(), fn (array $row): bool => $row['amount'] > 0));
        });
    }

    /**
     * Books a month's depreciation (YYYY-MM) for every active asset as one
     * voucher on the last day of the month: the cost per asset against its
     * accumulated depreciation. Null when there is nothing to book.
     */
    public function depreciate(User $user, Company $company, string $month): ?Voucher
    {
        $this->authorize($user, $company);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => __('Välj en månad.')]);
        }

        return CurrentCompany::run($company, fn (): ?Voucher => DB::transaction(function () use ($user, $company, $month): ?Voucher {
            FixedAsset::query()->where('status', FixedAsset::ACTIVE)->lockForUpdate()->get(['id']);
            $rows = $this->preview($company, $month);

            if ($rows === []) {
                return null;
            }

            $this->ensureAccounts(collect($rows)->flatMap(fn (array $row): array => [(int) $row['asset']->expense_account, (int) $row['asset']->accumulated_account])->unique()->all());
            $costCenters = CostCenter::query()->whereIn('id', collect($rows)->pluck('asset.cost_center_id')->filter())->pluck('code', 'id');
            $lines = [];

            foreach ($rows as $row) {
                $asset = $row['asset'];
                $lines[] = ['account' => (int) $asset->expense_account, 'amount' => $row['amount'], 'description' => "{$asset->number} {$asset->name}", 'cost_center' => $costCenters[$asset->cost_center_id] ?? null];
                $lines[] = ['account' => (int) $asset->accumulated_account, 'amount' => -$row['amount'], 'description' => "{$asset->number} {$asset->name}"];
            }

            $date = CarbonImmutable::parse("{$month}-01")->endOfMonth()->startOfDay();
            $voucher = $this->book->rows($user, $company, $date, __('Avskrivningar :month', ['month' => $month]), self::SERIES, $lines, VoucherSource::Closing, $rows[0]['asset']);

            foreach ($rows as $row) {
                FixedAssetDepreciation::create(['fixed_asset_id' => $row['asset']->id, 'period' => $month, 'amount_ore' => $row['amount'], 'voucher_id' => $voucher->id]);
            }

            Audit::record('fixed_asset.depreciated', $voucher, ['month' => $month, 'assets' => count($rows), 'amount_ore' => array_sum(array_column($rows, 'amount'))], $company, $user);

            return $voucher;
        }));
    }

    /**
     * Splits an asset into components from the first day of a month (K3 17.4,
     * 17.11, 17.13; at the transition to K3, 35.18; ADR-059 A.2.4). Only in a
     * K3 fiscal year: under K2 building and land are separate assets and no
     * component exists (K2 10.22).
     *
     * The cost of each component is the person's allocation and must add up
     * to the asset's; the depreciation written off by the split is divided by
     * ComponentSplit. Each component writes off its carrying amount over its
     * remaining life from the split (DepreciationBasis::CarryingAmount), so no
     * month before the split is booked again; land is never depreciated. The
     * asset gets the status SPLIT and is not depreciated again; its booked
     * depreciation and vouchers are left as they are. When a component uses
     * another asset account than the asset (land on 1130), one voucher moves
     * its cost there on the split date; otherwise no voucher is booked.
     *
     * A split is made from the first K3 fiscal year on. When comparatives are
     * restated at the transition (K3 35.7, 35.18), the comparative year's
     * depreciation per component is a presentation restatement, not a split in
     * a K2 year (ADR-059 A.2.4, decision of 2026-11-08). S1-P12 supports only
     * the transition without restated comparatives: K3TransitionProposal
     * blocks `comparatives_restated_at_transition` = `yes`, so no comparative
     * year is ever restated per component, and it blocks the transition until
     * every building of the register is split on the transition date, the
     * first day of the first K3 year (35.18; ADR-059 P12 addendum).
     *
     * @param  array<int, mixed>  $components  each: name, kind (part|land), cost, accumulated (optional), life_months (remaining, from the split), total_life_months (optional: the remaining life plus the months since the asset's first depreciation month), residual, asset_account
     */
    public function split(User $user, Company $company, int $assetId, string $date, array $components): FixedAsset
    {
        $this->authorize($user, $company);

        if (preg_match('/^\d{4}-\d{2}-01$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), 1, (int) substr($date, 0, 4))) {
            throw ValidationException::withMessages(['split_date' => __('Delningen gäller från den första dagen i en månad.')]);
        }

        /** @var list<array<string, mixed>> $validated */
        $validated = array_values(Validator::make(['components' => $components], [
            'components' => ['required', 'array', 'min:2', 'max:20'],
            'components.*' => ['required', 'array'],
            'components.*.name' => ['required', 'string', 'max:255'],
            'components.*.kind' => ['required', Rule::in([self::COMPONENT_PART, self::COMPONENT_LAND])],
            'components.*.cost' => ['required', 'string', 'max:40'],
            'components.*.accumulated' => ['nullable', 'string', 'max:40'],
            'components.*.residual' => ['nullable', 'string', 'max:40'],
            'components.*.life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'components.*.total_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'components.*.asset_account' => ['nullable', 'integer'],
        ], [], [
            'components' => __('komponenter'),
            'components.*.name' => __('benämning'),
            'components.*.cost' => __('anskaffningsvärde'),
            'components.*.life_months' => __('återstående nyttjandeperiod'),
            'components.*.total_life_months' => __('total nyttjandeperiod'),
            'components.*.asset_account' => __('tillgångskonto'),
        ])->validate()['components']);

        $splitOn = CarbonImmutable::parse($date);

        return CurrentCompany::run($company, fn (): FixedAsset => DB::transaction(function () use ($user, $company, $assetId, $splitOn, $validated): FixedAsset {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::NUMBERING_LOCK, $company->id]);

            // Inside the company: another company's asset answers 404.
            /** @var FixedAsset $asset */
            $asset = FixedAsset::query()->whereKey($assetId)->lockForUpdate()->firstOrFail();
            $this->assertSplittable($company, $asset, $splitOn);

            $parts = [];

            foreach ($validated as $index => $input) {
                $land = $input['kind'] === self::COMPONENT_LAND;

                if ($land && $asset->category !== AssetCategory::Buildings) {
                    throw ValidationException::withMessages(["components.{$index}.kind" => __('Mark kan bara vara en komponent i en byggnad.')]);
                }

                $accumulated = trim((string) ($input['accumulated'] ?? '')) === '' ? null : $this->money((string) $input['accumulated'], "components.{$index}.accumulated");
                $parts[] = ['cost_ore' => $this->money((string) $input['cost'], "components.{$index}.cost"), 'land' => $land, 'accumulated_ore' => $land && $accumulated === 0 ? null : $accumulated];
            }

            $accumulatedAtSplit = $asset->accumulatedOre();

            try {
                $allocation = ComponentSplit::allocate($asset->acquisition_ore->ore, $accumulatedAtSplit, $parts);
            } catch (ComponentSplitRefused $refused) {
                throw ValidationException::withMessages(['components' => $refused->getMessage()]);
            }

            $start = $splitOn->greaterThan($asset->depreciation_start) ? $splitOn : $asset->depreciation_start;
            $number = (int) FixedAsset::query()->max('number');
            $created = [];

            foreach ($validated as $index => $input) {
                $land = $input['kind'] === self::COMPONENT_LAND;
                $category = $land ? AssetCategory::Land : $asset->category;
                $cost = $allocation[$index]['cost_ore'];
                $opening = $allocation[$index]['accumulated_ore'];
                $residual = $land ? 0 : $this->money((string) ($input['residual'] ?? ''), "components.{$index}.residual");
                $life = $land ? 0 : (int) ($input['life_months'] ?? 0);
                $account = blank($input['asset_account'] ?? null) ? ($land ? AssetCategory::Land->accounts()['asset'] : $asset->asset_account) : (int) $input['asset_account'];

                if (! $land && ($life < 1 || $life > $category->maxLifeMonths())) {
                    throw ValidationException::withMessages(["components.{$index}.life_months" => __('Den återstående nyttjandeperioden ska vara mellan 1 och :max månader.', ['max' => $category->maxLifeMonths()])]);
                }

                // The total life (K3 17.23): entered, else the remaining life plus the months already gone.
                $total = $land ? null : (blank($input['total_life_months'] ?? null) ? $life + $this->monthsBetween($asset->depreciation_start, $start) : (int) $input['total_life_months']);

                if ($total !== null && $total < $life) {
                    throw ValidationException::withMessages(["components.{$index}.total_life_months" => __('Den totala nyttjandeperioden kan inte vara kortare än den återstående.')]);
                }

                if (blank($input['asset_account'] ?? null) && ! $land && ! self::isComponentAccount($account)) {
                    throw ValidationException::withMessages(["components.{$index}.asset_account" => __('Tillgångens konto :account kan inte användas för en komponent. Välj ett tillgångskonto mellan 1100 och 1299 för komponenten.', ['account' => $account])]);
                }

                if (! self::isComponentAccount($account)) {
                    throw ValidationException::withMessages(["components.{$index}.asset_account" => __('Tillgångskontot för en komponent ska vara ett konto för materiella anläggningstillgångar (1100–1299), inte ett konto för ackumulerade avskrivningar.')]);
                }

                if ($residual < 0 || $residual >= $cost || $opening > $cost - $residual) {
                    throw ValidationException::withMessages(["components.{$index}.residual" => __('Restvärdet och gjorda avskrivningar får tillsammans inte vara större än komponentens anskaffningsvärde.')]);
                }

                $created[] = FixedAsset::create([
                    'number' => ++$number,
                    'name' => (string) $input['name'],
                    'category' => $category,
                    'acquired_on' => $asset->acquired_on->toDateString(),
                    'acquisition_ore' => $cost,
                    'residual_ore' => $residual,
                    'opening_accumulated_ore' => $opening,
                    'useful_life_months' => $life,
                    'total_useful_life_months' => $total,
                    'depreciation_start' => $start->toDateString(),
                    'asset_account' => $account,
                    'accumulated_account' => $land ? null : $asset->accumulated_account,
                    'expense_account' => $land ? null : $asset->expense_account,
                    'cost_center_id' => $asset->cost_center_id,
                    'status' => FixedAsset::ACTIVE,
                    'parent_id' => $asset->id,
                    'depreciation_basis' => $land ? DepreciationBasis::Cost : DepreciationBasis::CarryingAmount,
                    'created_by' => $user->id,
                ]);
            }

            $voucher = $this->reclassify($user, $company, $asset, $created, $splitOn);
            $asset->forceFill(['status' => FixedAsset::SPLIT])->save();

            // Ids, dates and amounts only; the names are free text and stay in their rows.
            Audit::record('fixed_asset.split', $asset, [
                'split_on' => $splitOn->toDateString(),
                'component_ids' => array_map(fn (FixedAsset $component): int => $component->id, $created),
                'acquisition_ore' => $asset->acquisition_ore->ore,
                'accumulated_ore' => $accumulatedAtSplit,
                'voucher_id' => $voucher?->id,
            ], $company, $user);

            return $asset->setRelation('components', collect($created));
        }));
    }

    /**
     * What a split would start from, for the form: the remaining life of the
     * asset's plan at the split.
     */
    public static function remainingMonths(FixedAsset $asset, CarbonImmutable $splitOn): int
    {
        return max(1, $asset->useful_life_months - self::monthsBetween($asset->depreciation_start, $splitOn));
    }

    /**
     * A component's asset account: a tangible fixed asset account (BAS
     * 1100–1299), never one for accumulated depreciation (ending in 9).
     *
     * TODO(legal-verify): the range against the BAS 2026 chart (1100–1199
     * byggnader och mark, 1200–1299 maskiner och inventarier).
     */
    public static function isComponentAccount(int $account): bool
    {
        return $account >= 1100 && $account <= 1299 && $account % 10 !== 9;
    }

    /** Whole months from the first month to the second, never negative. */
    private static function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return max(0, ($to->year - $from->year) * 12 + ($to->month - $from->month));
    }

    private function assertSplittable(Company $company, FixedAsset $asset, CarbonImmutable $splitOn): void
    {
        if ($asset->isSplit()) {
            throw ValidationException::withMessages(['asset' => __('Tillgången är redan uppdelad i komponenter.')]);
        }

        if ($asset->status !== FixedAsset::ACTIVE) {
            throw ValidationException::withMessages(['asset' => __('Tillgången är avyttrad och kan inte delas upp.')]);
        }

        if ($asset->isComponent()) {
            throw ValidationException::withMessages(['asset' => __('En komponent kan inte delas upp i fler komponenter.')]);
        }

        if (! $asset->depreciates()) {
            throw ValidationException::withMessages(['asset' => __('Mark skrivs inte av och delas inte upp i komponenter.')]);
        }

        // Shared lock on the split year and every later one: a change of framework (ChangeReportingFramework) locks
        // the years from its year on for update, so a split and a change of any of those years never pass each other.
        $years = FiscalYear::query()->whereDate('end_date', '>=', $splitOn->toDateString())->orderBy('start_date')->sharedLock()->get();
        $fiscalYear = $years->first(fn (FiscalYear $year): bool => $year->contains($splitOn));

        if ($fiscalYear === null) {
            throw ValidationException::withMessages(['split_date' => __('Det finns inget räkenskapsår för :date.', ['date' => $splitOn->toDateString()])]);
        }

        if (! $fiscalYear->isOpen()) {
            throw ValidationException::withMessages(['split_date' => __('Räkenskapsåret :year är stängt. Dela upp tillgången från ett öppet räkenskapsår.', ['year' => $fiscalYear->label()])]);
        }

        if ($company->locked_until_date !== null && $splitOn->lessThanOrEqualTo($company->locked_until_date)) {
            throw ValidationException::withMessages(['split_date' => __('Perioden till och med :date är låst. Dela upp tillgången från en månad efter det.', ['date' => $company->locked_until_date->toDateString()])]);
        }

        if ($fiscalYear->reporting_framework !== ReportingFramework::K3) {
            throw ValidationException::withMessages(['split_date' => __('Komponenter används bara de räkenskapsår som företaget följer K3. Räkenskapsåret :year följer :framework.', ['year' => $fiscalYear->label(), 'framework' => $fiscalYear->reporting_framework->value])]);
        }

        $booked = $asset->depreciations()->where('period', '>=', $splitOn->format('Y-m'))->orderBy('period')->value('period');

        if ($booked !== null) {
            throw ValidationException::withMessages(['split_date' => __('Avskrivningen för :month är redan bokförd. Dela upp tillgången från en senare månad.', ['month' => $booked])]);
        }

        // The carrying amount at the split is what the plan says: a month left unbooked would never be caught up.
        $previous = $splitOn->subMonthNoOverflow()->format('Y-m');

        if ($asset->plan()->amountFor($previous, $asset->accumulatedOre()) <= 0) {
            return;
        }

        // Nothing booked in the register but something written off before it: the plan does not match the books.
        if ($asset->opening_accumulated_ore->ore > 0 && ! $asset->depreciations()->exists()) {
            throw ValidationException::withMessages(['split_date' => __('Registrets plan för :name stämmer inte med det som redan är avskrivet: enligt planen skulle mer vara avskrivet till och med :month. Ändra första avskrivningsmånad, nyttjandeperiod eller Redan avskrivet så att planen stämmer med bokföringen, och dela sedan upp tillgången.', ['name' => $asset->name, 'month' => $previous])]);
        }

        if (! $asset->depreciations()->exists()) {
            throw ValidationException::withMessages(['split_date' => __('Bokför avskrivningarna till och med :month i registret, eller – om de redan är bokförda utanför registret – ändra planen så att den stämmer.', ['month' => $previous])]);
        }

        throw ValidationException::withMessages(['split_date' => __('Bokför avskrivningarna till och med :month innan tillgången delas upp.', ['month' => $previous])]);
    }

    /**
     * Moves the cost of the components booked on another asset account than
     * the asset's (land on 1130) on the split date, as one voucher. The
     * accumulated depreciation stays where it is: depreciable components keep
     * the asset's accumulated account and land has none. Null when every
     * component uses the asset's own account.
     *
     * @param  list<FixedAsset>  $components
     */
    private function reclassify(User $user, Company $company, FixedAsset $asset, array $components, CarbonImmutable $splitOn): ?Voucher
    {
        $moved = array_values(array_filter($components, fn (FixedAsset $component): bool => $component->asset_account !== $asset->asset_account));

        if ($moved === []) {
            return null;
        }

        $this->ensureAccounts(array_values(array_unique([$asset->asset_account, ...array_map(fn (FixedAsset $component): int => $component->asset_account, $moved)])));
        $lines = [];

        foreach ($moved as $component) {
            $lines[] = ['account' => $component->asset_account, 'amount' => $component->acquisition_ore->ore, 'description' => "{$component->number} {$component->name}"];
        }

        $lines[] = ['account' => $asset->asset_account, 'amount' => -array_sum(array_map(fn (FixedAsset $component): int => $component->acquisition_ore->ore, $moved)), 'description' => "{$asset->number} {$asset->name}"];

        return $this->book->rows($user, $company, $splitOn, __('Omföring vid uppdelning i komponenter: :number :name', ['number' => $asset->number, 'name' => $asset->name]), self::SERIES, $lines, VoucherSource::Closing, $asset);
    }

    /**
     * Sells or scraps an asset: the acquisition value and its accumulated
     * depreciation leave the books, the price (excluding VAT) is booked on
     * the given account, and the difference is a gain (3973) or a loss
     * (7973). Book the month's depreciation first if the plan calls for it.
     *
     * TODO(legal-verify): VAT on the sale of an inventarie, and whether the
     * month of disposal should be depreciated, are left to the user.
     */
    public function dispose(User $user, FixedAsset $asset, string $date, string $price, int $counterAccount = 1930): FixedAsset
    {
        $company = Company::query()->findOrFail($asset->company_id);
        $this->authorize($user, $company);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages(['disposed_on' => __('Välj datum.')]);
        }

        $priceOre = $this->money($price, 'disposal_price');

        if ($priceOre < 0) {
            throw ValidationException::withMessages(['disposal_price' => __('Priset kan inte vara negativt.')]);
        }

        return CurrentCompany::run($company, fn (): FixedAsset => DB::transaction(function () use ($user, $company, $asset, $date, $priceOre, $counterAccount): FixedAsset {
            /** @var FixedAsset $asset */
            $asset = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($asset->isSplit()) {
                throw ValidationException::withMessages(['asset' => __('Tillgången är uppdelad i komponenter. Sälj eller utrangera komponenterna var för sig.')]);
            }

            if ($asset->status !== FixedAsset::ACTIVE) {
                throw ValidationException::withMessages(['asset' => __('Tillgången är redan avyttrad.')]);
            }

            $acquisition = $asset->acquisition_ore->ore;
            $accumulated = $asset->accumulatedOre();
            $result = $priceOre - ($acquisition - $accumulated);
            // TODO(legal-verify): for a building or a building component the gain or loss may belong
            // on 3972/7972 (byggnader och mark) rather than 3973/7973 (ADR-059 A.2.4, S1-R3.5).
            $this->ensureAccounts(array_values(array_filter([$asset->asset_account, $accumulated !== 0 ? $asset->accumulated_account : null, $counterAccount, $result >= 0 ? self::GAIN_ACCOUNT : self::LOSS_ACCOUNT], fn (?int $account): bool => $account !== null)));

            $lines = array_values(array_filter([
                ['account' => $asset->asset_account, 'amount' => -$acquisition, 'description' => "{$asset->number} {$asset->name}"],
                $accumulated !== 0 ? ['account' => (int) $asset->accumulated_account, 'amount' => $accumulated, 'description' => "{$asset->number} {$asset->name}"] : null,
                ['account' => $counterAccount, 'amount' => $priceOre, 'description' => null],
                ['account' => $result >= 0 ? self::GAIN_ACCOUNT : self::LOSS_ACCOUNT, 'amount' => -$result, 'description' => null],
            ]));

            $voucher = $this->book->rows($user, $company, CarbonImmutable::parse($date), ($priceOre > 0 ? __('Försäljning') : __('Utrangering')).": {$asset->number} {$asset->name}", self::SERIES, $lines, VoucherSource::Closing, $asset);

            $asset->forceFill([
                'status' => FixedAsset::DISPOSED,
                'disposed_on' => $date,
                'disposal_price_ore' => $priceOre,
                'disposal_voucher_id' => $voucher->id,
            ])->save();
            Audit::record('fixed_asset.disposed', $asset, ['voucher' => $voucher->reference(), 'price_ore' => $priceOre, 'result_ore' => $result], $company, $user);

            return $asset;
        }));
    }

    /**
     * The register's accounts the chart lacks, among the suggested ones.
     *
     * @return list<int>
     */
    public static function missingAccounts(Company $company): array
    {
        return CurrentCompany::run($company, function (): array {
            $suggested = array_keys(AssetCategory::ACCOUNT_NAMES);
            $properties = array_map(fn (AssetCategory $category): string => $category->value, array_filter(AssetCategory::cases(), fn (AssetCategory $category): bool => $category->isProperty()));

            // Buildings and land are seldom in the register; their accounts are proposed once one is.
            if (! FixedAsset::query()->whereIn('category', $properties)->exists()) {
                $suggested = array_values(array_diff($suggested, AssetCategory::PROPERTY_ACCOUNTS));
            }

            return array_values(array_diff(
                $suggested,
                Account::query()->whereIn('number', $suggested)->pluck('number')->map(fn (mixed $n): int => (int) $n)->all(),
            ));
        });
    }

    /** @return list<int> the accounts added */
    public function addAccounts(User $user, Company $company): array
    {
        $this->authorize($user, $company);

        if (! $user->hasTeamPermission($company, 'accounts:manage')) {
            throw new AuthorizationException;
        }

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company): array {
            $added = self::missingAccounts($company);

            foreach ($added as $number) {
                Account::create(['number' => $number, 'name' => AssetCategory::ACCOUNT_NAMES[$number], 'type' => AccountType::forNumber($number)]);
            }

            Audit::record('fixed_asset.accounts_added', null, ['accounts' => $added], $company, $user);

            return $added;
        }));
    }

    /**
     * @param  array<int, int>  $numbers
     */
    private function ensureAccounts(array $numbers): void
    {
        $existing = Account::query()->whereIn('number', $numbers)->where('is_active', true)->pluck('number')->map(fn (mixed $n): int => (int) $n)->all();
        $missing = array_values(array_diff($numbers, $existing));

        if ($missing !== []) {
            throw ValidationException::withMessages(['accounts' => __('Konto :accounts finns inte i kontoplanen. Lägg till det först.', ['accounts' => implode(', ', $missing)])]);
        }
    }

    private function money(string $value, string $field): int
    {
        if (trim($value) === '') {
            return 0;
        }

        try {
            return Money::parse($value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.')]);
        }
    }

    private function authorize(User $user, Company $company): void
    {
        if (! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }
    }
}
