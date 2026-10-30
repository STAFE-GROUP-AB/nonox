<?php

declare(strict_types=1);

use App\Actions\Assets\FixedAssets;
use App\Domain\Assets\DepreciationPlan;
use App\Livewire\Accounting\FixedAssetsPage;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->assets = app(FixedAssets::class);
    $this->assets->addAccounts($this->owner, $this->company);
});

/** @param array<string, string> $overrides */
function espressoMachine(array $overrides = []): FixedAsset
{
    return test()->assets->save(test()->owner, test()->company, [
        'name' => 'Espressomaskin', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-15', 'acquisition' => '40 000',
        'residual' => '', 'opening_accumulated' => '', 'life_months' => '60', 'depreciation_start' => '2026-01-01',
        'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832', 'cost_center' => '', 'notes' => '',
        ...$overrides,
    ]);
}

/** @return array<int, int> signed öre per account number */
function voucherSums(Voucher $voucher): array
{
    return $voucher->load('rows.account')->rows->groupBy(fn ($row) => $row->account->number)
        ->map(fn ($rows) => $rows->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore))->all();
}

it('plans linear depreciation that adds up exactly, whatever the rounding', function () {
    $plan = new DepreciationPlan(1_000_000, 0, 3, CarbonImmutable::parse('2026-01-01'));

    expect(array_column($plan->schedule(), 'amount'))->toBe([333_333, 333_334, 333_333])
        ->and($plan->accumulatedBy('2025-12'))->toBe(0)
        ->and($plan->accumulatedBy('2026-03'))->toBe(1_000_000)
        ->and($plan->accumulatedBy('2027-01'))->toBe(1_000_000)
        ->and($plan->amountFor('2026-02', 0))->toBe(666_667)
        ->and($plan->lastMonth())->toBe('2026-03');
});

it('books a month of depreciation once, as one voucher, and catches up a missed month', function () {
    $machine = espressoMachine();
    espressoMachine(['name' => 'Bärbar dator', 'category' => 'COMPUTERS', 'acquisition' => '18 000', 'life_months' => '36', 'asset_account' => '1250', 'accumulated_account' => '1259', 'expense_account' => '7835', 'depreciation_start' => '2026-03-01']);

    $voucher = $this->assets->depreciate($this->owner, $this->company, '2026-02');

    expect($voucher->voucher_date->toDateString())->toBe('2026-02-28')
        ->and(voucherSums($voucher))->toEqualCanonicalizing([1229 => -133_333, 7832 => 133_333])
        ->and($this->assets->depreciate($this->owner, $this->company, '2026-02'))->toBeNull();

    $march = $this->assets->depreciate($this->owner, $this->company, '2026-03');

    expect(voucherSums($march))->toEqualCanonicalizing([7832 => 66_667, 7835 => 50_000, 1229 => -66_667, 1259 => -50_000])
        ->and($machine->fresh()->accumulatedOre())->toBe(200_000)
        ->and($machine->fresh()->bookValueOre())->toBe(3_800_000);
});

it('takes over an asset from another system with what it had written off', function () {
    $machine = espressoMachine(['acquired_on' => '2024-01-10', 'depreciation_start' => '2024-01-01', 'opening_accumulated' => '16 000']);

    $voucher = $this->assets->depreciate($this->owner, $this->company, '2026-01');

    // 25 of 60 months by the end of January 2026: 16 666,67 kr, of which 16 000 kr was written off before.
    expect(voucherSums($voucher)[7832])->toBe(66_667)
        ->and($machine->fresh()->accumulatedOre())->toBe(1_666_667);
});

it('sells an asset and books the gain, or scraps it and books the loss', function () {
    $machine = espressoMachine();
    $this->assets->depreciate($this->owner, $this->company, '2026-03');

    $sold = $this->assets->dispose($this->owner, $machine, '2026-03-31', '39 000');
    $sale = Voucher::query()->findOrFail($sold->disposal_voucher_id);

    expect($sold->status)->toBe('DISPOSED')
        ->and(voucherSums($sale))->toBe([1220 => -4_000_000, 1229 => 200_000, 1930 => 3_900_000, 3973 => -100_000])
        ->and(fn () => $this->assets->dispose($this->owner, $sold, '2026-04-01', '0'))->toThrow(ValidationException::class);

    $scrapped = $this->assets->dispose($this->owner, espressoMachine(['name' => 'Kyl']), '2026-03-31', '0');
    expect(voucherSums(Voucher::query()->findOrFail($scrapped->disposal_voucher_id)))->toMatchArray([1220 => -4_000_000, 7973 => 4_000_000]);
});

it('keeps the plan of booked months and the booked depreciation itself', function () {
    $machine = espressoMachine();
    $this->assets->depreciate($this->owner, $this->company, '2026-01');

    $saved = $this->assets->save($this->owner, $this->company, ['name' => 'Espressomaskin La Marzocco', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-15', 'acquisition' => '99 000', 'life_months' => '12', 'depreciation_start' => '2026-01-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832'], $machine);

    expect($saved->name)->toBe('Espressomaskin La Marzocco')
        ->and($saved->fresh()->acquisition_ore->ore)->toBe(4_000_000)
        ->and(fn () => FixedAssetDepreciation::query()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('fixed_asset_depreciations')->update(['amount_ore' => 1]))->toThrow(QueryException::class);
});

it('is for those who close the year, and lists the register with book values', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    espressoMachine();
    expect(fn () => $this->assets->depreciate($bookkeeper, $this->company, '2026-01'))->toThrow(AuthorizationException::class);

    Livewire::test(FixedAssetsPage::class)
        ->assertSee('Espressomaskin')
        ->set('month', '2026-03')
        ->assertSee('Att skriva av för 2026-03')
        ->call('depreciate')
        ->assertDispatched('toast')
        ->call('create')
        ->set('form.category', 'COMPUTERS')
        ->assertSet('form.expense_account', '7835')
        ->assertSet('form.life_months', '36');

    $this->actingAs($bookkeeper);
    $this->get(route('accounting.fixedAssets'))->assertForbidden();
});
