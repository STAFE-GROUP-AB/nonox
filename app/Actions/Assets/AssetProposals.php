<?php

declare(strict_types=1);

namespace App\Actions\Assets;

use App\Domain\Assets\AcquisitionAccounts;
use App\Domain\Assets\AssetCategory;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Models\FixedAssetProposal;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Assets created from the purchase that books them: a voucher row or a
 * supplier invoice row debited to one of the company's acquisition accounts
 * (AcquisitionAccounts) can carry the register's fields. On a draft they are
 * kept as proposals; the asset itself is created inside the same transaction
 * that posts the voucher (PostVoucher) or registers the invoice
 * (RegisterSupplierInvoice), linked to what booked it. If the posting
 * fails, there is no asset. Depreciation is booked by the register as usual.
 */
class AssetProposals
{
    /**
     * Replaces a draft's proposals, after its rows are saved. Must run in the
     * draft's company context and transaction. A proposal on a row that is
     * not a purchase on an acquisition account is dropped.
     *
     * @param  array<int, mixed>  $proposals  the register's fields per line, counted from zero among the saved rows
     *
     * @throws ValidationException
     */
    public function sync(User $user, Company $company, Voucher|SupplierInvoice $draft, array $proposals): void
    {
        $eligible = $this->eligibleLines($company, $draft);
        $clean = [];
        $errors = [];

        foreach ($proposals as $line => $proposal) {
            if (! is_array($proposal) || ! array_key_exists((int) $line, $eligible)) {
                continue;
            }

            $validator = Validator::make($proposal, [
                'name' => ['required', 'string', 'max:255'],
                'category' => ['required', Rule::enum(AssetCategory::class)],
                'acquisition' => ['nullable', 'string', 'max:30'],
                'life_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
                'acquired_on' => ['nullable', 'date_format:Y-m-d'],
                'depreciation_start' => ['nullable', 'date_format:Y-m'],
            ]);
            $label = __('Rad :line: anläggningstillgången:', ['line' => (int) $line + 1]);

            if ($validator->fails()) {
                $errors["rows.{$line}.asset"] = "{$label} ".$validator->errors()->first();

                continue;
            }

            // As the register itself: land has no life (it is never depreciated), the rest 1 to the category's longest.
            $category = AssetCategory::from((string) $proposal['category']);
            $life = blank($proposal['life_months'] ?? null) ? 0 : (int) $proposal['life_months'];

            if ($category->depreciates() && ($life < 1 || $life > $category->maxLifeMonths())) {
                $errors["rows.{$line}.asset"] = "{$label} ".__('Nyttjandeperioden ska vara mellan 1 och :max månader.', ['max' => $category->maxLifeMonths()]);

                continue;
            }

            $acquisition = trim((string) ($proposal['acquisition'] ?? ''));

            try {
                if ($acquisition !== '' && Money::parse($acquisition)->ore <= 0) {
                    $errors["rows.{$line}.asset"] = "{$label} ".__('Anskaffningsvärdet ska vara större än noll.');

                    continue;
                }
            } catch (InvalidArgumentException) {
                $errors["rows.{$line}.asset"] = "{$label} ".__('Beloppet går inte att läsa. Skriv till exempel 12 500 eller 12 500,50.');

                continue;
            }

            $clean[(int) $line] = [
                'name' => trim((string) $proposal['name']),
                'category' => (string) $proposal['category'],
                'acquisition' => $acquisition,
                'life_months' => $category->depreciates() ? $life : 0,
                'acquired_on' => blank($proposal['acquired_on'] ?? null) ? null : (string) $proposal['acquired_on'],
                'depreciation_start' => blank($proposal['depreciation_start'] ?? null) ? null : (string) $proposal['depreciation_start'],
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($clean !== [] && ! $user->hasTeamPermission($company, 'fiscal-years:manage')) {
            throw new AuthorizationException;
        }

        $had = self::proposalsFor($draft)->count();
        self::proposalsFor($draft)->delete();

        foreach ($clean as $line => $data) {
            FixedAssetProposal::create([
                ...$this->owner($draft),
                'line' => $line,
                'data' => $data,
                'created_by' => $user->id,
            ]);
        }

        if ($clean !== [] || $had > 0) {
            Audit::record('fixed_asset.proposed', $draft, ['lines' => array_keys($clean), 'names' => array_column($clean, 'name')], $company, $user);
        }
    }

    /**
     * Creates the proposed assets of a voucher being posted, linked to it and
     * to their rows. Runs inside PostVoucher's transaction.
     *
     * @throws ValidationException
     */
    public function createForVoucher(User $user, Voucher $voucher): void
    {
        $proposals = FixedAssetProposal::query()->where('voucher_id', $voucher->id)->orderBy('line')->get();

        if ($proposals->isEmpty()) {
            return;
        }

        $company = Company::query()->findOrFail($voucher->company_id);
        $accounts = AcquisitionAccounts::for($company);
        $rows = VoucherRow::query()->where('voucher_id', $voucher->id)->with(['account', 'costCenter'])->orderBy('id')->get()->values();

        foreach ($proposals as $proposal) {
            $row = $rows->get($proposal->line);

            if (! $row instanceof VoucherRow || ! $row->account instanceof Account || ! $this->isPurchase($accounts, $row->account->number, $row->debit_ore->ore, $row->credit_ore->ore)) {
                throw ValidationException::withMessages(["rows.{$proposal->line}.asset" => __('Rad :line: anläggningstillgången hör inte längre till en rad med inköp på ett anskaffningskonto. Ta bort förslaget eller rätta raden.', ['line' => $proposal->line + 1])]);
            }

            $this->create($user, $company, $proposal, $row->account->number, $row->debit_ore->ore, $voucher->voucher_date->toDateString(), $row->costCenter, [
                'acquisition_voucher_id' => $voucher->id,
                'acquisition_voucher_row_id' => $row->id,
            ]);
        }

        FixedAssetProposal::query()->where('voucher_id', $voucher->id)->delete();
    }

    /**
     * Creates the proposed assets of a supplier invoice being registered,
     * linked to the invoice and, under fakturametoden, to the voucher and the
     * voucher row that booked each cost row. Runs inside RegisterSupplierInvoice's
     * transaction.
     *
     * @throws ValidationException
     */
    public function createForSupplierInvoice(User $user, SupplierInvoice $invoice, ?Voucher $voucher): void
    {
        $proposals = FixedAssetProposal::query()->where('supplier_invoice_id', $invoice->id)->orderBy('line')->get();

        if ($proposals->isEmpty()) {
            return;
        }

        $company = Company::query()->findOrFail($invoice->company_id);
        $eligible = $this->eligibleLines($company, $invoice);
        $rows = SupplierInvoiceRow::query()->where('supplier_invoice_id', $invoice->id)->with(['account', 'costCenter'])->orderBy('position')->get()->values();
        // The cost rows come first in the voucher, in the invoice's order (SupplierInvoiceBooking::register).
        $voucherRows = $voucher === null ? collect() : VoucherRow::query()->where('voucher_id', $voucher->id)->with('account')->orderBy('id')->get()->values();

        foreach ($proposals as $proposal) {
            $row = $rows->get($proposal->line);

            if (! $row instanceof SupplierInvoiceRow || ! $row->account instanceof Account || ! array_key_exists($proposal->line, $eligible)) {
                throw ValidationException::withMessages(["rows.{$proposal->line}.asset" => __('Rad :line: anläggningstillgången hör inte längre till en rad med inköp på ett anskaffningskonto. Ta bort förslaget eller rätta raden.', ['line' => $proposal->line + 1])]);
            }

            $voucherRow = $voucherRows->get($proposal->line);
            $linkedRow = $voucherRow instanceof VoucherRow && $voucherRow->account?->number === $row->account->number ? $voucherRow : null;

            $this->create($user, $company, $proposal, $row->account->number, $row->amount_ore->ore, $invoice->invoice_date->toDateString(), $row->costCenter, [
                'acquisition_voucher_id' => $voucher?->id,
                'acquisition_voucher_row_id' => $linkedRow?->id,
                'supplier_invoice_id' => $invoice->id,
            ]);
        }

        FixedAssetProposal::query()->where('supplier_invoice_id', $invoice->id)->delete();
    }

    /**
     * A draft's proposals for its editor, per line.
     *
     * @return array<int, array{name: string, category: string, acquisition: string, life_months: string, acquired_on: string, depreciation_start: string}>
     */
    public static function forms(Voucher|SupplierInvoice $draft): array
    {
        return self::proposalsFor($draft)->get()->mapWithKeys(fn (FixedAssetProposal $proposal): array => [$proposal->line => [
            'name' => (string) ($proposal->data['name'] ?? ''),
            'category' => (string) ($proposal->data['category'] ?? AssetCategory::Equipment->value),
            'acquisition' => (string) ($proposal->data['acquisition'] ?? ''),
            'life_months' => (string) ($proposal->data['life_months'] ?? ''),
            'acquired_on' => (string) ($proposal->data['acquired_on'] ?? ''),
            'depreciation_start' => (string) ($proposal->data['depreciation_start'] ?? ''),
        ]])->all();
    }

    /**
     * What the editors need to offer an asset per row: the acquisition
     * accounts, the threshold and each category's suggestions.
     *
     * @return array{ranges: list<array{0: int, 1: int}>, threshold: ?int, categories: list<array{value: string, label: string, life: int, asset: int}>}
     */
    public static function editorSettings(Company $company): array
    {
        return [
            'ranges' => AcquisitionAccounts::for($company)->ranges,
            'threshold' => $company->asset_small_value_threshold_ore,
            'categories' => array_map(fn (AssetCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
                'life' => $category->suggestedLifeMonths(),
                'asset' => $category->accounts()['asset'],
            ], AssetCategory::cases()),
        ];
    }

    /**
     * @param  array{acquisition_voucher_id?: ?string, acquisition_voucher_row_id?: ?int, supplier_invoice_id?: ?string}  $links
     *
     * @throws ValidationException
     */
    private function create(User $user, Company $company, FixedAssetProposal $proposal, int $account, int $amountOre, string $date, ?CostCenter $costCenter, array $links): FixedAsset
    {
        $data = $proposal->data;
        $category = AssetCategory::tryFrom($data['category']) ?? AcquisitionAccounts::categoryFor($account);
        $acquiredOn = (string) $data['acquired_on'] !== '' ? (string) $data['acquired_on'] : $date;
        $start = (string) $data['depreciation_start'] !== '' ? (string) $data['depreciation_start'] : substr($acquiredOn, 0, 7);

        try {
            // Resolved here: FixedAssets books through PostVoucher, which itself creates assets through this class.
            return app(FixedAssets::class)->createFromPurchase($user, $company, [
                'name' => $data['name'],
                'category' => $category->value,
                'acquired_on' => $acquiredOn,
                'acquisition' => $data['acquisition'] !== '' ? $data['acquisition'] : Money::ofOre($amountOre)->format(withCurrency: false),
                'residual' => '',
                'opening_accumulated' => '',
                'life_months' => (int) $data['life_months'],
                'depreciation_start' => "{$start}-01",
                'asset_account' => $account,
                'accumulated_account' => $category->accounts()['accumulated'],
                'expense_account' => $category->accounts()['expense'],
                'cost_center' => $costCenter?->code,
                'notes' => null,
            ], $links);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(["rows.{$proposal->line}.asset" => __('Rad :line: anläggningstillgången kunde inte skapas: :reason', [
                'line' => $proposal->line + 1,
                'reason' => (string) collect($exception->errors())->flatten()->first(),
            ])]);
        }
    }

    /**
     * The draft's saved rows that are purchases on an acquisition account,
     * by line.
     *
     * @return array<int, true>
     */
    private function eligibleLines(Company $company, Voucher|SupplierInvoice $draft): array
    {
        $accounts = AcquisitionAccounts::for($company);
        $eligible = [];

        if ($draft instanceof Voucher) {
            foreach (VoucherRow::query()->where('voucher_id', $draft->id)->with('account')->orderBy('id')->get()->values() as $line => $row) {
                if ($row->account instanceof Account && $this->isPurchase($accounts, $row->account->number, $row->debit_ore->ore, $row->credit_ore->ore)) {
                    $eligible[(int) $line] = true;
                }
            }

            return $eligible;
        }

        if ($draft->isCredit() || $draft->vat_treatment->buyerIsLiable()) {
            return [];
        }

        foreach (SupplierInvoiceRow::query()->where('supplier_invoice_id', $draft->id)->with('account')->orderBy('position')->get()->values() as $line => $row) {
            if ($row->account instanceof Account && $this->isPurchase($accounts, $row->account->number, $row->amount_ore->ore, 0)) {
                $eligible[(int) $line] = true;
            }
        }

        return $eligible;
    }

    private function isPurchase(AcquisitionAccounts $accounts, int $account, int $debit, int $credit): bool
    {
        return $debit > 0 && $credit === 0 && $accounts->contains($account);
    }

    /** @return Builder<FixedAssetProposal> */
    private static function proposalsFor(Voucher|SupplierInvoice $draft): Builder
    {
        return $draft instanceof Voucher
            ? FixedAssetProposal::query()->where('voucher_id', $draft->id)
            : FixedAssetProposal::query()->where('supplier_invoice_id', $draft->id);
    }

    /** @return array{voucher_id?: string, supplier_invoice_id?: string} */
    private function owner(Voucher|SupplierInvoice $draft): array
    {
        return $draft instanceof Voucher ? ['voucher_id' => (string) $draft->id] : ['supplier_invoice_id' => (string) $draft->id];
    }
}
