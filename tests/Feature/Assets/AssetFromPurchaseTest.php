<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Assets\FixedAssets;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Accounting\VoucherSource;
use App\Domain\Assets\AcquisitionAccounts;
use App\Domain\Assets\AssetCategory;
use App\Livewire\Accounting\FixedAssetsPage;
use App\Livewire\Accounting\VoucherEditor;
use App\Livewire\Payables\SupplierInvoiceEditor;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Models\FixedAssetProposal;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    app(FixedAssets::class)->addAccounts($this->owner, $this->company);
});

/**
 * An espresso machine for 40 000 kr plus 25 % VAT, paid from the bank, with
 * the given proposal on the first row.
 *
 * @param  array<string, mixed>|null  $asset
 */
function machinePurchase(User $user, Company $company, ?array $asset, bool $withAssets = true, int $account = 1220, ?Voucher $draft = null, string $costCenter = ''): Voucher
{
    return app(SaveDraftVoucher::class)($user, $company, [
        'voucher_date' => '2026-03-20',
        'description' => 'Espressomaskin från Kaffegrossisten',
        'rows' => [
            ['account' => $account, 'debit_ore' => 4_000_000, 'credit_ore' => 0, 'description' => 'Espressomaskin', 'cost_center' => $costCenter, 'asset' => $asset],
            ['account' => 2641, 'debit_ore' => 1_000_000, 'credit_ore' => 0],
            ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 5_000_000],
        ],
        'with_assets' => $withAssets,
    ], $draft);
}

/** @return array<string, mixed> */
function machineProposal(array $overrides = []): array
{
    return ['name' => 'Espressomaskin', 'category' => 'EQUIPMENT', 'acquisition' => '', 'life_months' => '60', 'acquired_on' => '', 'depreciation_start' => '', ...$overrides];
}

it('creates the asset when the voucher is posted, linked to the voucher and its row', function () {
    CostCenter::create(['code' => '10', 'name' => 'Restaurang Hamnen']);
    $draft = machinePurchase($this->owner, $this->company, machineProposal(), costCenter: '10');

    expect(FixedAsset::query()->count())->toBe(0)
        ->and(FixedAssetProposal::query()->where('voucher_id', $draft->id)->count())->toBe(1);

    $posted = app(PostVoucher::class)($this->owner, $draft);
    $asset = FixedAsset::query()->sole();

    expect($asset->name)->toBe('Espressomaskin')
        ->and($asset->number)->toBe(1)
        ->and($asset->category)->toBe(AssetCategory::Equipment)
        ->and($asset->acquisition_ore->ore)->toBe(4_000_000)
        ->and($asset->acquired_on->toDateString())->toBe('2026-03-20')
        ->and($asset->depreciation_start->toDateString())->toBe('2026-03-01')
        ->and($asset->useful_life_months)->toBe(60)
        ->and([$asset->asset_account, $asset->accumulated_account, $asset->expense_account])->toBe([1220, 1229, 7832])
        ->and($asset->costCenter->code)->toBe('10')
        ->and($asset->acquisition_voucher_id)->toBe($posted->id)
        ->and($asset->acquisition_voucher_row_id)->toBe($posted->rows->first()->id)
        ->and($asset->acquisitionVoucher->reference())->toBe('A1')
        ->and($posted->acquiredAssets()->pluck('id')->all())->toBe([$asset->id])
        ->and(FixedAssetProposal::query()->count())->toBe(0)
        ->and($asset->depreciations()->count())->toBe(0)
        // Only the purchase was booked; depreciation is the register's monthly job.
        ->and(Voucher::query()->where('source', VoucherSource::Closing)->count())->toBe(0);

    $audit = AuditLog::query()->where('action', 'fixed_asset.created')->sole();
    expect($audit->payload['acquisition_voucher_id'])->toBe($posted->id);
});

it('uses the proposal’s own value, date and first month when they are given', function () {
    $draft = machinePurchase($this->owner, $this->company, machineProposal(['acquisition' => '42 500', 'acquired_on' => '2026-03-25', 'depreciation_start' => '2026-04', 'category' => 'MACHINERY', 'life_months' => '120']));
    app(PostVoucher::class)($this->owner, $draft);
    $asset = FixedAsset::query()->sole();

    expect($asset->acquisition_ore->ore)->toBe(4_250_000)
        ->and($asset->acquired_on->toDateString())->toBe('2026-03-25')
        ->and($asset->depreciation_start->toDateString())->toBe('2026-04-01')
        ->and($asset->useful_life_months)->toBe(120)
        ->and($asset->asset_account)->toBe(1220)
        ->and([$asset->accumulated_account, $asset->expense_account])->toBe([1219, 7831]);
});

it('creates no asset when the posting fails, and keeps the proposal with the draft', function () {
    $draft = machinePurchase($this->owner, $this->company, machineProposal());
    $this->company->forceFill(['locked_until_date' => '2026-03-31'])->save();

    expect(fn () => app(PostVoucher::class)($this->owner, $draft))->toThrow(ValidationException::class, 'låst');

    expect(FixedAsset::query()->count())->toBe(0)
        ->and(FixedAssetProposal::query()->where('voucher_id', $draft->id)->count())->toBe(1)
        ->and($draft->fresh()->number)->toBeNull();
});

it('rolls back the posting when the asset cannot be created', function () {
    $draft = machinePurchase($this->owner, $this->company, machineProposal());
    // A proposal that no longer passes the register's rules, written past the editor.
    FixedAssetProposal::query()->update(['data' => [...machineProposal(), 'life_months' => 0]]);

    expect(fn () => app(PostVoucher::class)($this->owner, $draft))->toThrow(ValidationException::class, 'Rad 1: anläggningstillgången kunde inte skapas');

    expect(FixedAsset::query()->count())->toBe(0)
        ->and($draft->fresh()->number)->toBeNull()
        ->and(AuditLog::query()->where('action', 'voucher.posted')->count())->toBe(0);
});

it('checks the proposal and points the error at the row', function () {
    expect(fn () => machinePurchase($this->owner, $this->company, machineProposal(['name' => ''])))
        ->toThrow(ValidationException::class, 'Rad 1: anläggningstillgången:');

    try {
        machinePurchase($this->owner, $this->company, machineProposal(['acquisition' => 'mycket']));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['rows.0.asset']);
    }
});

it('drops a proposal on a row that is not a purchase on an acquisition account', function () {
    machinePurchase($this->owner, $this->company, machineProposal(), account: 5410);

    expect(FixedAssetProposal::query()->count())->toBe(0);
});

it('leaves the proposals as they are when the rows come without them', function () {
    $draft = machinePurchase($this->owner, $this->company, machineProposal());
    machinePurchase($this->owner, $this->company, null, withAssets: false, draft: $draft);

    expect(FixedAssetProposal::query()->count())->toBe(1);

    machinePurchase($this->owner, $this->company, null, draft: $draft);

    expect(FixedAssetProposal::query()->count())->toBe(0);
});

it('refuses a stale proposal when the row it belonged to has changed', function () {
    $draft = machinePurchase($this->owner, $this->company, machineProposal());
    machinePurchase($this->owner, $this->company, null, withAssets: false, account: 5410, draft: $draft);

    expect(fn () => app(PostVoucher::class)($this->owner, $draft))->toThrow(ValidationException::class, 'hör inte längre till en rad med inköp');
    expect(FixedAsset::query()->count())->toBe(0);
});

it('lets only those who keep the register propose assets, and a bookkeeper post the draft', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => machinePurchase($bookkeeper, $this->company, machineProposal()))->toThrow(AuthorizationException::class);

    $draft = machinePurchase($this->owner, $this->company, machineProposal());
    app(PostVoucher::class)($bookkeeper, $draft);

    expect(FixedAsset::query()->sole()->created_by)->toBe($bookkeeper->id);
});

it('offers the register’s asset accounts by default and the company’s own once chosen', function () {
    expect(AcquisitionAccounts::for($this->company)->ranges)->toBe([[1220, 1220], [1250, 1250], [1210, 1210], [1240, 1240]])
        ->and(AcquisitionAccounts::for($this->company)->contains(1229))->toBeFalse()
        ->and(AcquisitionAccounts::categoryFor(1221))->toBe(AssetCategory::Equipment)
        ->and(AcquisitionAccounts::categoryFor(1251))->toBe(AssetCategory::Computers);

    $company = app(FixedAssets::class)->saveSettings($this->owner, $this->company, '1220, 1221-1227', '13 000');

    expect($company->asset_acquisition_accounts)->toBe([[1220, 1220], [1221, 1227]])
        ->and($company->asset_small_value_threshold_ore)->toBe(1_300_000)
        ->and(AcquisitionAccounts::for($company)->contains(1225))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'fixed_asset.settings_changed')->count())->toBe(1);

    expect(fn () => app(FixedAssets::class)->saveSettings($this->owner, $this->company, '1229-1220', ''))->toThrow(ValidationException::class)
        ->and(fn () => app(FixedAssets::class)->saveSettings($this->owner, $this->company, '3001', ''))->toThrow(ValidationException::class)
        ->and(fn () => app(FixedAssets::class)->saveSettings(Bookkeeping::member($this->company, 'bookkeeper'), $this->company, '', ''))->toThrow(AuthorizationException::class);

    $company = app(FixedAssets::class)->saveSettings($this->owner, $this->company, '', '');

    expect($company->asset_acquisition_accounts)->toBeNull()
        ->and($company->asset_small_value_threshold_ore)->toBeNull();
});

it('creates the asset when a supplier invoice is registered, linked to the invoice, voucher and row', function () {
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Kaffegrossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30]);
    $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id,
        'invoice_date' => '2026-03-18',
        'total' => '50 125,00',
        'vat' => '10 025,00',
        'rows' => [
            ['account' => '4010', 'amount' => '100,00', 'description' => 'Kaffebönor'],
            ['account' => '1220', 'amount' => '40 000', 'description' => 'Espressomaskin', 'asset' => machineProposal(['name' => 'Espressomaskin Hamnen'])],
        ],
        'with_assets' => true,
    ]);

    expect(FixedAssetProposal::query()->sole()->line)->toBe(1);

    $invoice = app(RegisterSupplierInvoice::class)($this->owner, $draft);
    $asset = FixedAsset::query()->sole();
    $row = $invoice->voucher->rows()->with('account')->get()->first(fn ($row) => $row->account->number === 1220);

    expect($asset->name)->toBe('Espressomaskin Hamnen')
        ->and($asset->acquisition_ore->ore)->toBe(4_000_000)
        ->and($asset->acquired_on->toDateString())->toBe('2026-03-18')
        ->and($asset->supplier_invoice_id)->toBe($invoice->id)
        ->and($asset->acquisition_voucher_id)->toBe($invoice->voucher_id)
        ->and($asset->acquisition_voucher_row_id)->toBe($row->id)
        ->and(FixedAssetProposal::query()->count())->toBe(0);
});

it('never offers an asset on a credit note', function () {
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Kaffegrossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30]);
    app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id, 'invoice_date' => '2026-03-18', 'total' => '50 000', 'vat' => '10 000', 'credit' => true,
        'rows' => [['account' => '1220', 'amount' => '40 000', 'asset' => machineProposal()]],
        'with_assets' => true,
    ]);

    expect(FixedAssetProposal::query()->count())->toBe(0);
});

it('takes the proposal from the voucher editor, keeps it with the draft and shows the link both ways', function () {
    $rows = [
        ['account' => '1220 Inventarier och verktyg', 'debit' => '40 000', 'credit' => '', 'description' => 'Espressomaskin', 'cost_center' => '', 'project' => '', 'asset' => machineProposal()],
        ['account' => '2641', 'debit' => '10 000', 'credit' => '', 'description' => '', 'cost_center' => '', 'project' => ''],
        ['account' => '1930', 'debit' => '', 'credit' => '50 000', 'description' => '', 'cost_center' => '', 'project' => ''],
    ];

    Livewire::test(VoucherEditor::class)
        ->assertSee('Anläggningstillgångar')
        ->set('voucherDate', '2026-03-20')
        ->set('description', 'Espressomaskin')
        ->set('rows', $rows)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $draft = Voucher::query()->sole();
    $editor = Livewire::test(VoucherEditor::class, ['voucher' => $draft]);

    expect($editor->get('rows')[0]['asset']['name'])->toBe('Espressomaskin');

    $editor->call('post')->assertHasNoErrors();

    $asset = FixedAsset::query()->sole();
    expect($asset->acquisition_voucher_id)->toBe($draft->id);

    $this->get(route('accounting.vouchers.show', $draft))->assertOk()->assertSee('Anläggningstillgångar som skapades')->assertSee('1 Espressomaskin');
    Livewire::test(FixedAssetsPage::class)->assertSee('Anskaffad med verifikation')->assertSee('A1');
});

it('hides the offer from those who do not keep the register', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));

    Livewire::test(VoucherEditor::class)->assertDontSee('Skapa anläggningstillgång');
});

it('saves the settings from the register page', function () {
    Livewire::test(FixedAssetsPage::class)
        ->assertSet('settings.accounts', '1220, 1250, 1210, 1240')
        ->set('settings.accounts', '1220-1227')
        ->set('settings.threshold', '13 000')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSet('settings.threshold', Money::ofOre(1_300_000)->format(withCurrency: false));

    expect($this->company->fresh()->asset_acquisition_accounts)->toBe([[1220, 1227]]);
});

it('takes the proposal from the supplier invoice editor', function () {
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Kaffegrossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30]);

    Livewire::test(SupplierInvoiceEditor::class)
        ->assertSee('Skapa anläggningstillgång')
        ->set('supplierId', (string) $supplier->id)
        ->set('invoiceDate', '2026-03-18')
        ->set('total', '50 000')
        ->set('vat', '10 000')
        ->set('rows', [['account' => '1220', 'amount' => '40 000', 'description' => 'Espressomaskin', 'cost_center' => '', 'project' => '', 'vat_rate' => '2500', 'asset' => machineProposal()]])
        ->call('register')
        ->assertHasNoErrors();

    expect(FixedAsset::query()->sole()->supplier_invoice_id)->not->toBeNull();
});
