<?php

declare(strict_types=1);

namespace App\Actions\Assets;

use App\Actions\Accounting\BookVoucher;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Assets\AssetCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
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

    public function __construct(private readonly BookVoucher $book) {}

    /**
     * @param  array<string, mixed>  $input  name, category, acquired_on, acquisition, residual, opening_accumulated, life_months, depreciation_start, asset_account, accumulated_account, expense_account, cost_center, notes
     */
    public function save(User $user, Company $company, array $input, ?FixedAsset $asset = null): FixedAsset
    {
        $this->authorize($user, $company);

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(AssetCategory::class)],
            'acquired_on' => ['required', 'date_format:Y-m-d'],
            'acquisition' => ['required', 'string'],
            'residual' => ['nullable', 'string'],
            'opening_accumulated' => ['nullable', 'string'],
            'life_months' => ['required', 'integer', 'min:1', 'max:600'],
            'depreciation_start' => ['required', 'date_format:Y-m-d'],
            'asset_account' => ['required', 'integer', 'between:1000,1999'],
            'accumulated_account' => ['required', 'integer', 'between:1000,1999'],
            'expense_account' => ['required', 'integer', 'between:7000,7999'],
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

        return CurrentCompany::run($company, fn (): FixedAsset => DB::transaction(function () use ($user, $company, $validated, $acquisition, $residual, $opening, $asset): FixedAsset {
            if ($asset !== null && $asset->depreciations()->exists()) {
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
                'useful_life_months' => (int) $validated['life_months'],
                'depreciation_start' => CarbonImmutable::parse((string) $validated['depreciation_start'])->startOfMonth()->toDateString(),
                'asset_account' => (int) $validated['asset_account'],
                'accumulated_account' => (int) $validated['accumulated_account'],
                'expense_account' => (int) $validated['expense_account'],
                'cost_center_id' => $costCenter?->id,
                'notes' => $validated['notes'] ?? null,
            ];

            if ($asset === null) {
                // The company row is the lock, so two new assets never get the same number.
                Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                $asset = FixedAsset::create([
                    ...$attributes,
                    'number' => (int) FixedAsset::query()->max('number') + 1,
                    'status' => FixedAsset::ACTIVE,
                    'created_by' => $user->id,
                ]);
                Audit::record('fixed_asset.created', $asset, ['name' => $asset->name, 'acquisition_ore' => $acquisition], $company, $user);

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

            $this->ensureAccounts(collect($rows)->flatMap(fn (array $row): array => [$row['asset']->expense_account, $row['asset']->accumulated_account])->unique()->all());
            $costCenters = CostCenter::query()->whereIn('id', collect($rows)->pluck('asset.cost_center_id')->filter())->pluck('code', 'id');
            $lines = [];

            foreach ($rows as $row) {
                $asset = $row['asset'];
                $lines[] = ['account' => $asset->expense_account, 'amount' => $row['amount'], 'description' => "{$asset->number} {$asset->name}", 'cost_center' => $costCenters[$asset->cost_center_id] ?? null];
                $lines[] = ['account' => $asset->accumulated_account, 'amount' => -$row['amount'], 'description' => "{$asset->number} {$asset->name}"];
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

            if ($asset->status !== FixedAsset::ACTIVE) {
                throw ValidationException::withMessages(['asset' => __('Tillgången är redan avyttrad.')]);
            }

            $acquisition = $asset->acquisition_ore->ore;
            $accumulated = $asset->accumulatedOre();
            $result = $priceOre - ($acquisition - $accumulated);
            $this->ensureAccounts([$asset->asset_account, $asset->accumulated_account, $counterAccount, $result >= 0 ? self::GAIN_ACCOUNT : self::LOSS_ACCOUNT]);

            $lines = [
                ['account' => $asset->asset_account, 'amount' => -$acquisition, 'description' => "{$asset->number} {$asset->name}"],
                ['account' => $asset->accumulated_account, 'amount' => $accumulated, 'description' => "{$asset->number} {$asset->name}"],
                ['account' => $counterAccount, 'amount' => $priceOre, 'description' => null],
                ['account' => $result >= 0 ? self::GAIN_ACCOUNT : self::LOSS_ACCOUNT, 'amount' => -$result, 'description' => null],
            ];

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
        return CurrentCompany::run($company, fn (): array => array_values(array_diff(
            array_keys(AssetCategory::ACCOUNT_NAMES),
            Account::query()->whereIn('number', array_keys(AssetCategory::ACCOUNT_NAMES))->pluck('number')->map(fn (mixed $n): int => (int) $n)->all(),
        )));
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
