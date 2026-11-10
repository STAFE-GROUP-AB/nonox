<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Assets\FixedAssets;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Assets\DepreciationBasis;
use App\Livewire\Accounting\FixedAssetsPage;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetAddition;
use App\Models\FixedAssetDepreciation;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * One investment built from several purchases (ADR-010, fixed asset
 * additions): a later voucher row or supplier invoice added to an existing
 * asset, before or after depreciation has started.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->assets = app(FixedAssets::class);
    $this->assets->addAccounts($this->owner, $this->company);
});

/** @param  array<string, string>  $overrides */
function kitchen(array $overrides = []): FixedAsset
{
    return app(FixedAssets::class)->save(test()->owner, test()->company, [
        'name' => 'Storköksutrustning', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-10', 'acquisition' => '60 000',
        'life_months' => '60', 'depreciation_start' => '2026-01-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832',
        ...$overrides,
    ]);
}

/** A posted purchase of the amount (öre) on the account, paid from the bank; its debit row. */
function purchaseRow(string $date, int $ore, int $account = 1220): VoucherRow
{
    $voucher = Bookkeeping::postRows(test()->owner, test()->company, $date, [$account => $ore, 1930 => -$ore]);

    return $voucher->rows()->where('debit_ore', '>', 0)->sole();
}

it('adds a posted purchase made before depreciation starts to the plan from its first month', function () {
    $asset = kitchen(['acquired_on' => '2026-04-01', 'acquisition' => '100 000', 'depreciation_start' => '2026-09-01']);
    $row = purchaseRow('2026-05-20', 5_000_000);

    $addition = $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id);
    $asset->refresh();

    expect($asset->acquisition_ore->ore)->toBe(15_000_000)
        ->and($asset->originalAcquisitionOre())->toBe(10_000_000)
        ->and($addition->voucher_row_id)->toBe($row->id)
        ->and($addition->voucher_id)->toBe($row->voucher_id)
        ->and($addition->booked_through)->toBeNull()
        ->and($asset->additionTerms($addition->added_on, null)['from']->toDateString())->toBe('2026-09-01')
        // Not in use yet: nothing to write off before the first month.
        ->and($this->assets->preview($this->company, '2026-06'))->toBe([])
        ->and($this->assets->preview($this->company, '2026-09')[0]['amount'])->toBe(250_000)
        ->and(array_sum(array_column($asset->plan()->schedule(), 'amount')))->toBe(15_000_000);
});

it('writes a later purchase off prospectively over the remaining life and never changes a booked month', function () {
    $asset = kitchen();

    foreach (['2026-01', '2026-02', '2026-03'] as $month) {
        $this->assets->depreciate($this->owner, $this->company, $month);
    }

    $booked = FixedAssetDepreciation::query()->orderBy('period')->get(['period', 'amount_ore', 'voucher_id'])->toArray();
    $vouchers = Voucher::query()->count();
    $row = purchaseRow('2026-03-15', 1_200_000);

    $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id);
    $asset->refresh();
    $terms = $asset->additionTerms(CarbonImmutable::parse('2026-03-15'), '2026-03');

    expect($terms['from']->toDateString())->toBe('2026-04-01')
        ->and($terms['life'])->toBe(57)
        // 1 000 kr a month for the asset, 12 000 kr over the remaining 57 months for the addition.
        ->and($this->assets->preview($this->company, '2026-04')[0]['amount'])->toBe(100_000 + intdiv(1_200_000 + 28, 57))
        ->and(FixedAssetDepreciation::query()->orderBy('period')->get(['period', 'amount_ore', 'voucher_id'])->toArray())->toEqual($booked)
        ->and(Voucher::query()->count())->toBe($vouchers + 1);

    // The whole plan still ends in the last month and writes off exactly the acquisition value.
    $schedule = $asset->plan()->schedule();
    expect(end($schedule)['month'])->toBe('2030-12')
        ->and(end($schedule)['book_value'])->toBe(0)
        ->and(array_sum(array_column($schedule, 'amount')))->toBe(7_200_000);

    $this->assets->depreciate($this->owner, $this->company, '2026-04');
    expect(FixedAssetDepreciation::query()->where('period', '2026-04')->sole()->amount_ore->ore)->toBe(121_053);
});

it('starts a purchase dated in a booked month after the last booked month', function () {
    $asset = kitchen();

    foreach (['2026-01', '2026-02', '2026-03'] as $month) {
        $this->assets->depreciate($this->owner, $this->company, $month);
    }

    $row = purchaseRow('2026-02-10', 570_000);
    $addition = $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id);

    expect($addition->booked_through)->toBe('2026-03')
        ->and($asset->fresh()->additionTerms($addition->added_on, $addition->booked_through)['from']->toDateString())->toBe('2026-04-01')
        // No catch-up of February and March: 5 700 kr over 57 months is 100 kr a month.
        ->and($this->assets->preview($this->company, '2026-04')[0]['amount'])->toBe(110_000);
});

it('refuses an addition after the useful life has ended', function () {
    $asset = kitchen(['life_months' => '3']);

    foreach (['2026-01', '2026-02', '2026-03'] as $month) {
        $this->assets->depreciate($this->owner, $this->company, $month);
    }

    $row = purchaseRow('2026-04-05', 100_000);

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id))
        ->toThrow(ValidationException::class, 'nyttjandeperiod tog slut 2026-03');
    expect(FixedAssetAddition::query()->count())->toBe(0)
        ->and($asset->fresh()->acquisition_ore->ore)->toBe(6_000_000);
});

it('refuses an addition in a locked period or a closed fiscal year', function () {
    $asset = kitchen();
    $row = purchaseRow('2026-03-15', 100_000);
    $this->company->forceFill(['locked_until_date' => '2026-03-31'])->save();

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id))
        ->toThrow(ValidationException::class, 'låst');

    $this->company->forceFill(['locked_until_date' => null])->save();
    FiscalYear::query()->update(['status' => 'CLOSED']);

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id))
        ->toThrow(ValidationException::class, 'stängt');
    expect(FixedAssetAddition::query()->count())->toBe(0);
});

it('refuses a row on another account, a credit, a row already in the register, and a disposed asset', function () {
    $asset = kitchen();
    $machine = purchaseRow('2026-03-15', 100_000, 1210);

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $asset->id, $machine->id))
        ->toThrow(ValidationException::class, 'konto 1210 men tillgången på 1220');

    $credit = Bookkeeping::postRows($this->owner, $this->company, '2026-03-16', [1930 => 100_000, 1220 => -100_000])->rows()->where('credit_ore', '>', 0)->sole();

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $asset->id, $credit->id))
        ->toThrow(ValidationException::class, 'Bara en rad i debet');

    $row = purchaseRow('2026-03-17', 100_000);
    $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id);

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id))
        ->toThrow(ValidationException::class, 'hör redan till en tillgång');

    $other = purchaseRow('2026-03-18', 100_000);
    $this->assets->dispose($this->owner, $asset->fresh(), '2026-03-31', '0');

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $asset->id, $other->id))
        ->toThrow(ValidationException::class, 'avyttrad');
});

it('keeps additions append-only, also in the database', function () {
    $asset = kitchen();
    $addition = $this->assets->addPurchase($this->owner, $this->company, $asset->id, purchaseRow('2026-03-15', 100_000)->id);

    expect(fn () => $addition->forceFill(['amount_ore' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $addition->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('fixed_asset_additions')->where('id', $addition->id)->update(['amount_ore' => 1]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('fixed_asset_additions')->where('id', $addition->id)->delete())->toThrow(QueryException::class);
});

it('writes an audit entry with ids and amounts only', function () {
    $asset = kitchen();
    $row = purchaseRow('2026-03-15', 100_000);
    $addition = $this->assets->addPurchase($this->owner, $this->company, $asset->id, $row->id);

    $audit = AuditLog::query()->where('action', 'fixed_asset.addition_added')->sole();

    expect($audit->payload)->toEqual([
        'addition_id' => $addition->id,
        'amount_ore' => 100_000,
        'acquisition_ore' => 6_100_000,
        'added_on' => '2026-03-15',
        'depreciation_from' => '2026-04',
        'voucher_id' => $row->voucher_id,
        'voucher_row_id' => $row->id,
    ]);
});

it('adds the purchase to an existing asset when a voucher with an addition proposal is posted', function () {
    $asset = kitchen();
    $draft = app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-20',
        'description' => 'Diskmaskin till köket',
        'rows' => [
            ['account' => 1220, 'debit_ore' => 2_000_000, 'credit_ore' => 0, 'asset' => ['add_to' => (string) $asset->id, 'acquisition' => '']],
            ['account' => 2641, 'debit_ore' => 500_000, 'credit_ore' => 0],
            ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 2_500_000],
        ],
        'with_assets' => true,
    ]);

    expect(FixedAssetAddition::query()->count())->toBe(0);

    $posted = app(PostVoucher::class)($this->owner, $draft);
    $addition = FixedAssetAddition::query()->sole();

    expect(FixedAsset::query()->count())->toBe(1)
        ->and($asset->fresh()->acquisition_ore->ore)->toBe(8_000_000)
        ->and($addition->voucher_id)->toBe($posted->id)
        ->and($addition->voucher_row_id)->toBe($posted->rows->first()->id);
});

it('refuses an addition proposal for an asset that is not in the register', function () {
    expect(fn () => app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-20',
        'description' => 'Diskmaskin',
        'rows' => [
            ['account' => 1220, 'debit_ore' => 2_000_000, 'credit_ore' => 0, 'asset' => ['add_to' => '999999', 'acquisition' => '']],
            ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 2_000_000],
        ],
        'with_assets' => true,
    ]))->toThrow(ValidationException::class, 'Välj en tillgång i registret');
});

it('adds a supplier invoice row to an existing asset when the invoice is registered', function () {
    $asset = kitchen();
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Köksgrossisten AB', 'bankgiro' => '50501055', 'payment_terms_days' => 30]);
    $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id,
        'invoice_date' => '2026-03-18',
        'total' => '25 000',
        'vat' => '5 000',
        'rows' => [
            ['account' => '1220', 'amount' => '20 000', 'description' => 'Fläktkåpa', 'asset' => ['add_to' => (string) $asset->id, 'acquisition' => '']],
        ],
        'with_assets' => true,
    ]);

    $invoice = app(RegisterSupplierInvoice::class)($this->owner, $draft);
    $addition = FixedAssetAddition::query()->sole();

    expect($addition->supplier_invoice_id)->toBe($invoice->id)
        ->and($addition->voucher_id)->toBe($invoice->voucher_id)
        ->and($addition->amount_ore->ore)->toBe(2_000_000)
        ->and($asset->fresh()->acquisition_ore->ore)->toBe(8_000_000);
});

it('keeps the acquisition value of an asset with additions to the sum of its purchases', function () {
    $asset = kitchen(['depreciation_start' => '2026-09-01']);
    $this->assets->addPurchase($this->owner, $this->company, $asset->id, purchaseRow('2026-03-15', 1_000_000)->id);
    $form = ['name' => 'Storköksutrustning', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-10', 'acquisition' => '60 000', 'life_months' => '60', 'depreciation_start' => '2026-09-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832'];

    expect(fn () => $this->assets->save($this->owner, $this->company, $form, $asset->fresh()))
        ->toThrow(ValidationException::class, 'summan av inköpen');

    $saved = $this->assets->save($this->owner, $this->company, [...$form, 'acquisition' => '70 000', 'depreciation_start' => '2026-10-01'], $asset->fresh());

    expect($saved->acquisition_ore->ore)->toBe(7_000_000)
        ->and($saved->depreciation_start->toDateString())->toBe('2026-10-01');
});

it('refuses an addition to an asset split into components and accepts one to a component', function () {
    $this->company->forceFill(['reporting_framework' => 'K3'])->save();
    FiscalYear::query()->update(['reporting_framework' => 'K3']);
    $building = $this->assets->save($this->owner, $this->company, [
        'name' => 'Fastigheten', 'category' => 'BUILDINGS', 'acquired_on' => '2026-01-01', 'acquisition' => '1 000 000',
        'life_months' => '600', 'depreciation_start' => '2026-04-01', 'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820',
    ]);
    $this->assets->addAccounts($this->owner, $this->company);
    $split = $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', [
        ['name' => 'Stomme', 'kind' => 'part', 'cost' => '600 000', 'life_months' => '600'],
        ['name' => 'Tak', 'kind' => 'part', 'cost' => '400 000', 'life_months' => '300'],
    ]);
    $row = purchaseRow('2026-05-15', 3_000_000, 1110);

    expect(fn () => $this->assets->addPurchase($this->owner, $this->company, $building->id, $row->id))
        ->toThrow(ValidationException::class, 'uppdelad i komponenter');

    $roof = $split->components->firstWhere('name', 'Tak');
    $this->assets->addPurchase($this->owner, $this->company, $roof->id, $row->id);
    $roof->refresh();

    expect($roof->depreciation_basis)->toBe(DepreciationBasis::CarryingAmount)
        ->and($roof->acquisition_ore->ore)->toBe(43_000_000)
        // 400 000 kr over 300 months from April; 30 000 kr over the remaining 298 months from June.
        ->and($roof->plan()->accumulatedBy('2026-06'))->toBe(intdiv(40_000_000 * 3 + 150, 300) + intdiv(3_000_000 + 149, 298))
        ->and(array_sum(array_column($roof->plan()->schedule(), 'amount')))->toBe(43_000_000);
});

it('adds a purchase from the register page after showing what happens', function () {
    $asset = kitchen();
    $row = purchaseRow('2026-03-15', 1_200_000);

    Livewire::test(FixedAssetsPage::class)
        ->call('startAddition', $asset->id)
        ->assertSee('Lägg till inköp i Storköksutrustning')
        ->assertSee(Voucher::query()->findOrFail($row->voucher_id)->reference())
        ->set('additionRow', (string) $row->id)
        ->call('reviewAddition')
        ->assertSee('Anskaffningsvärdet ökar med '.Money::ofOre(1_200_000)->format().' till '.Money::ofOre(7_200_000)->format().'.')
        ->assertSee('Beloppet skrivs av från 2026-04 över återstående 57 månader av nyttjandeperioden.')
        ->call('addPurchase')
        ->assertHasNoErrors()
        ->assertSee('Anskaffad med flera inköp:');

    expect($asset->fresh()->acquisition_ore->ore)->toBe(7_200_000)
        ->and(FixedAssetAddition::query()->sole()->voucher_row_id)->toBe($row->id);

    $this->get(route('accounting.vouchers.show', $row->voucher_id))
        ->assertOk()
        ->assertSee('lades till i en befintlig anläggningstillgång')
        ->assertSee('Storköksutrustning');
});

it('lists the register with a fixed number of queries however many additions there are', function () {
    $count = function (int $assets, int $additions): int {
        foreach (range(1, $assets) as $i) {
            $asset = kitchen(['name' => "Tillgång {$i}"]);

            foreach (range(1, $additions) as $j) {
                $this->assets->addPurchase($this->owner, $this->company, $asset->id, purchaseRow('2026-03-'.str_pad((string) (10 + $j), 2, '0', STR_PAD_LEFT), 10_000 * $j)->id);
            }
        }

        $page = Livewire::test(FixedAssetsPage::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count(1, 1);

    expect($count(4, 5))->toBe($few);
});
