<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SetAccountingPolicy;
use App\Actions\Assets\FixedAssets;
use App\Domain\Accounting\LedgerVerifier;
use App\Domain\AnnualReport\K3Components;
use App\Domain\AnnualReport\K3Policies;
use App\Domain\AnnualReport\K3PolicyKey;
use App\Domain\Assets\DepreciationBasis;
use App\Livewire\Accounting\FixedAssetsPage;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * S1-P4 (ADR-059 A.2.4, A.4): splitting an asset into components in a K3
 * year (K3 17.4, 17.11, 17.13, 35.18; S1-R3, R3.2, R3.3, R3.4, R7.2 a).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->assets = app(FixedAssets::class);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->year->forceFill(['reporting_framework' => 'K3'])->save();
    $this->company->forceFill(['reporting_framework' => 'K3'])->save();
});

/**
 * A building of 1 200 000 kr over 50 years from 2016, with ten years written
 * off before the register and January–March 2026 booked: 24 600 000 öre
 * written off at 2026-04-01.
 */
function officeBuilding(): FixedAsset
{
    $building = test()->assets->save(test()->owner, test()->company, [
        'name' => 'Kontorsfastighet', 'category' => 'BUILDINGS', 'acquired_on' => '2016-01-01', 'acquisition' => '1 200 000',
        'residual' => '', 'opening_accumulated' => '240 000', 'life_months' => '600', 'depreciation_start' => '2016-01-01',
        'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820', 'cost_center' => '', 'notes' => '',
    ]);
    test()->assets->addAccounts(test()->owner, test()->company);

    foreach (['2026-01', '2026-02', '2026-03'] as $month) {
        test()->assets->depreciate(test()->owner, test()->company, $month);
    }

    return $building->fresh();
}

/** @return list<array<string, string>> */
function buildingComponents(array $overrides = []): array
{
    return array_replace_recursive([
        ['name' => 'Mark', 'kind' => 'land', 'cost' => '200 000', 'accumulated' => '', 'life_months' => '', 'residual' => '', 'asset_account' => '1130'],
        ['name' => 'Stomme', 'kind' => 'part', 'cost' => '600 000', 'accumulated' => '', 'life_months' => '500', 'residual' => '', 'asset_account' => ''],
        ['name' => 'Tak', 'kind' => 'part', 'cost' => '400 000', 'accumulated' => '', 'life_months' => '200', 'residual' => '', 'asset_account' => ''],
    ], $overrides);
}

/** @return array<int, int> signed öre per account number */
function componentVoucherSums(Voucher $voucher): array
{
    return $voucher->load('rows.account')->rows->groupBy(fn ($row) => $row->account->number)
        ->map(fn ($rows) => $rows->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore))->all();
}

it('S1-R3 S1-R7.2 a splits a building into land and components whose cost and depreciation add up exactly', function () {
    $building = officeBuilding();

    expect($building->accumulatedOre())->toBe(24_600_000);

    $split = $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', buildingComponents());
    $components = FixedAsset::query()->where('parent_id', $building->id)->orderBy('number')->get();

    expect($split->fresh()->status)->toBe(FixedAsset::SPLIT)
        ->and($components)->toHaveCount(3)
        ->and($components->sum(fn (FixedAsset $c) => $c->acquisition_ore->ore))->toBe(120_000_000)
        ->and($components->sum(fn (FixedAsset $c) => $c->opening_accumulated_ore->ore))->toBe(24_600_000)
        // Land gets none of the depreciation; stomme and tak share it 60:40 by cost.
        ->and($components->map(fn (FixedAsset $c) => [$c->name, $c->category->value, $c->asset_account, $c->opening_accumulated_ore->ore, $c->depreciation_basis])->all())->toBe([
            ['Mark', 'LAND', 1130, 0, DepreciationBasis::Cost],
            ['Stomme', 'BUILDINGS', 1110, 14_760_000, DepreciationBasis::CarryingAmount],
            ['Tak', 'BUILDINGS', 1110, 9_840_000, DepreciationBasis::CarryingAmount],
        ])
        ->and($components->every(fn (FixedAsset $c) => $c->depreciation_start->toDateString() === '2026-04-01'))->toBeTrue();

    // Land is on 1130, the building on 1110: one voucher moves the land's cost on the split date.
    $voucher = Voucher::query()->where('description', 'like', 'Omföring vid uppdelning i komponenter%')->sole();

    expect($voucher->voucher_date->toDateString())->toBe('2026-04-01')
        ->and(componentVoucherSums($voucher))->toEqualCanonicalizing([1130 => 20_000_000, 1110 => -20_000_000]);

    $audit = AuditLog::forCompany($this->company)->where('action', 'fixed_asset.split')->sole();

    expect(array_keys($audit->payload))->toEqualCanonicalizing(['split_on', 'component_ids', 'acquisition_ore', 'accumulated_ore', 'voucher_id'])
        ->and($audit->payload['component_ids'])->toBe($components->pluck('id')->all())
        ->and(json_encode($audit->payload))->not->toContain('Stomme')->not->toContain('Kontorsfastighet');
});

it('books no voucher when every component keeps the asset\'s accounts', function () {
    $building = officeBuilding();
    $before = Voucher::query()->count();

    $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', [
        ['name' => 'Stomme', 'kind' => 'part', 'cost' => '800 000', 'life_months' => '500'],
        ['name' => 'Tak', 'kind' => 'part', 'cost' => '400 000', 'life_months' => '200'],
    ]);

    expect(Voucher::query()->count())->toBe($before);
});

it('S1-R7.2 a books no catch-up month after a split; S1-R3.2 land is never depreciated; the split asset gets no depreciation', function () {
    $building = officeBuilding();
    $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', buildingComponents());

    $preview = $this->assets->preview($this->company, '2026-04');

    // Stomme: (60 000 000 − 14 760 000) / 500 = 90 480; tak: (40 000 000 − 9 840 000) / 200 = 150 800.
    expect(collect($preview)->map(fn ($row) => [$row['asset']->name, $row['amount']])->all())->toBe([['Stomme', 90_480], ['Tak', 150_800]]);

    $april = $this->assets->depreciate($this->owner, $this->company, '2026-04');

    expect(componentVoucherSums($april))->toEqualCanonicalizing([7820 => 241_280, 1119 => -241_280])
        ->and(FixedAssetDepreciation::query()->where('fixed_asset_id', $building->id)->where('period', '2026-04')->exists())->toBeFalse()
        ->and($this->assets->depreciate($this->owner, $this->company, '2026-04'))->toBeNull();

    // Over its remaining life each component writes off exactly its carrying amount at the split, nothing more.
    $stomme = FixedAsset::query()->where('name', 'Stomme')->sole();

    expect(array_sum(array_column($stomme->plan()->schedule(), 'amount')))->toBe(60_000_000 - 14_760_000)
        ->and($stomme->plan()->schedule()[0]['month'])->toBe('2026-04');

    // A month skipped after the split is caught up within the component's own plan, never a month before it.
    $may = $this->assets->preview($this->company, '2026-06');

    expect(collect($may)->sum('amount'))->toBe(2 * 241_280);

    $land = FixedAsset::query()->where('category', 'LAND')->sole();

    expect($land->plan()->amountFor('2040-12', 0))->toBe(0)
        ->and($land->bookValueOre())->toBe(20_000_000);
});

it('leaves booked depreciations and posted vouchers untouched by a split (immutability)', function () {
    $building = officeBuilding();
    $booked = DB::table('fixed_asset_depreciations')->orderBy('id')->get(['id', 'fixed_asset_id', 'period', 'amount_ore', 'voucher_id'])->toArray();
    $vouchers = DB::table('vouchers')->where('status', 'POSTED')->orderBy('id')->get(['id', 'number', 'hash'])->toArray();

    $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', buildingComponents());

    expect(DB::table('fixed_asset_depreciations')->orderBy('id')->get(['id', 'fixed_asset_id', 'period', 'amount_ore', 'voucher_id'])->toArray())->toEqual($booked)
        ->and(DB::table('vouchers')->where('status', 'POSTED')->whereIn('id', array_column($vouchers, 'id'))->orderBy('id')->get(['id', 'number', 'hash'])->toArray())->toEqual($vouchers)
        ->and(app(LedgerVerifier::class)->verify($this->company)['problems'])->toBe([]);
});

it('keeps the schedules of existing assets unchanged (cost basis)', function () {
    $machine = $this->assets->save($this->owner, $this->company, [
        'name' => 'Espressomaskin', 'category' => 'EQUIPMENT', 'acquired_on' => '2024-01-10', 'acquisition' => '40 000',
        'opening_accumulated' => '16 000', 'life_months' => '60', 'depreciation_start' => '2024-01-01',
        'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832',
    ]);
    $this->assets->addAccounts($this->owner, $this->company);

    expect($machine->fresh()->depreciation_basis)->toBe(DepreciationBasis::Cost)
        ->and(DB::table('fixed_assets')->where('id', $machine->id)->value('depreciation_basis'))->toBe('COST')
        // 25 of 60 months by the end of January 2026: the cost basis still catches up 666,67 kr.
        ->and(componentVoucherSums($this->assets->depreciate($this->owner, $this->company, '2026-01')))->toEqualCanonicalizing([7832 => 66_667, 1229 => -66_667]);
});

it('refuses a split it cannot make, with a Swedish reason', function (Closure $arrange, string $date, string $message) {
    $building = officeBuilding();
    $id = $arrange($building);

    expect(fn () => $this->assets->split($this->owner, $this->company, $id, $date, buildingComponents()))
        ->toThrow(ValidationException::class, $message);
})->with([
    'a K2 year' => [function (FixedAsset $building) {
        test()->year->forceFill(['reporting_framework' => 'K2'])->save();

        return $building->id;
    }, '2026-04-01', 'bara de räkenskapsår som företaget följer K3'],
    'not the first of a month' => [fn (FixedAsset $building) => $building->id, '2026-04-15', 'första dagen i en månad'],
    'a booked month at the split' => [fn (FixedAsset $building) => $building->id, '2026-03-01', 'Avskrivningen för 2026-03 är redan bokförd'],
    'an unbooked month before it' => [fn (FixedAsset $building) => $building->id, '2026-06-01', 'Bokför avskrivningarna till och med 2026-05'],
    'no fiscal year' => [fn (FixedAsset $building) => $building->id, '2031-01-01', 'inget räkenskapsår'],
]);

it('refuses to split a split asset, a component, land, or land in anything but a building', function () {
    $building = officeBuilding();
    $components = $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', buildingComponents())->components;

    expect(fn () => $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', buildingComponents()))->toThrow(ValidationException::class, 'redan uppdelad')
        ->and(fn () => $this->assets->split($this->owner, $this->company, $components[1]->id, '2026-04-01', buildingComponents()))->toThrow(ValidationException::class, 'En komponent kan inte delas upp')
        ->and(fn () => $this->assets->split($this->owner, $this->company, $components[0]->id, '2026-04-01', buildingComponents()))->toThrow(ValidationException::class, 'En komponent kan inte delas upp')
        ->and(fn () => $this->assets->dispose($this->owner, $building->fresh(), '2026-04-30', '0'))->toThrow(ValidationException::class, 'Sälj eller utrangera komponenterna var för sig');

    $land = $this->assets->save($this->owner, $this->company, [
        'name' => 'Tomt', 'category' => 'LAND', 'acquired_on' => '2026-04-01', 'acquisition' => '300 000',
        'depreciation_start' => '2026-04-01', 'asset_account' => '1130',
    ]);

    expect($land->useful_life_months)->toBe(0)
        ->and($land->accumulated_account)->toBeNull()
        ->and(fn () => $this->assets->split($this->owner, $this->company, $land->id, '2026-04-01', buildingComponents()))->toThrow(ValidationException::class, 'Mark skrivs inte av');

    $machine = $this->assets->save($this->owner, $this->company, [
        'name' => 'Svarv', 'category' => 'MACHINERY', 'acquired_on' => '2026-04-01', 'acquisition' => '100 000',
        'life_months' => '120', 'depreciation_start' => '2026-04-01', 'asset_account' => '1210', 'accumulated_account' => '1219', 'expense_account' => '7831',
    ]);

    expect(fn () => $this->assets->split($this->owner, $this->company, $machine->id, '2026-04-01', [
        ['name' => 'Mark', 'kind' => 'land', 'cost' => '50 000'],
        ['name' => 'Svarv', 'kind' => 'part', 'cost' => '50 000', 'life_months' => '120'],
    ]))->toThrow(ValidationException::class, 'Mark kan bara vara en komponent i en byggnad');
});

it('refuses component costs that do not add up to the asset\'s cost', function () {
    $building = officeBuilding();

    expect(fn () => $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', buildingComponents([2 => ['cost' => '300 000']])))
        ->toThrow(ValidationException::class, 'Komponenternas anskaffningsvärden ska tillsammans vara');
});

it('lets only those who close the year split an asset', function (string $role, bool $allowed) {
    $building = officeBuilding();
    $member = Bookkeeping::member($this->company, $role);
    $split = fn () => $this->assets->split($member, $this->company, $building->id, '2026-04-01', buildingComponents());

    $allowed
        ? expect($split()->status)->toBe(FixedAsset::SPLIT)
        : expect($split)->toThrow(AuthorizationException::class);
})->with([
    'admin' => ['admin', true],
    'accountant' => ['accountant', true],
    'bookkeeper' => ['bookkeeper', false],
    'approver' => ['approver', false],
    'auditor' => ['auditor', false],
]);

it('S1-R3.1 stores the H4 component policy for a K3 year', function () {
    $policy = app(SetAccountingPolicy::class)($this->owner, $this->company, $this->year->id, K3PolicyKey::Components, 'buildings_always_others_by_choice');

    expect($policy->policyValue())->toBe(K3Components::BuildingsAlwaysOthersByChoice)
        ->and(K3Policies::forYear($this->year)->missing())->not->toContain(K3PolicyKey::Components)
        ->and(K3Policies::forYear($this->year)->refusals())->toBe([]);
});

it('splits a building from the fixed asset page after a confirmation that states the consequences', function () {
    $building = officeBuilding();

    $page = Livewire::test(FixedAssetsPage::class)
        ->assertSee('Byggnaden '.$building->number.' Kontorsfastighet är inte uppdelad i komponenter')
        ->assertSee('Dela upp i komponenter')
        ->call('startSplit', $building->id)
        ->assertSet('splitDate', '2026-04-01')
        ->assertSet('components.0.kind', 'land')
        ->assertSet('components.1.name', 'Stomme')
        ->assertSet('components.1.life_months', '477')
        ->assertSee('Komponenter')
        ->assertSee('Kvar att fördela');

    $page->call('removeComponent', 4)->call('removeComponent', 3)
        ->set('components.0.cost', '200 000')
        ->set('components.1.cost', '600 000')->set('components.1.life_months', '500')
        ->set('components.2.cost', '400 000')->set('components.2.life_months', '200')
        ->call('reviewSplit')
        ->assertSet('confirmingSplit', true)
        ->assertSee('Uppdelningen går inte att ångra.')
        ->assertSee('Avskrivningar som redan är bokförda ändras inte')
        ->call('split')
        ->assertDispatched('toast')
        ->assertSet('splitting', null)
        ->assertSee('Uppdelad i komponenter')
        ->assertSee('Komponent i '.$building->number)
        ->assertSee('Skrivs inte av')
        ->assertDontSee('är inte uppdelad i komponenter');

    expect($building->fresh()->status)->toBe(FixedAsset::SPLIT);
});

it('shows a refused split as a Swedish toast and keeps the form (Livewire tampering)', function () {
    $building = officeBuilding();

    Livewire::test(FixedAssetsPage::class)
        ->call('startSplit', $building->id)
        ->set('components.1.kind', 'mark')
        ->set('splitDate', '2026-04-01')
        ->call('split')
        ->assertHasErrors('components.1.kind')
        ->assertSet('splitting', $building->id)
        ->set('components', [['name' => 'X', 'kind' => 'part', 'cost' => '1 200 000', 'life_months' => '12']])
        ->call('split')
        ->assertHasErrors('components');

    expect($building->fresh()->status)->toBe(FixedAsset::ACTIVE);
});

it('does not offer the split under K2 and refuses it there', function () {
    $this->company->forceFill(['reporting_framework' => 'K2'])->save();
    $this->year->forceFill(['reporting_framework' => 'K2'])->save();
    $building = officeBuilding();

    Livewire::test(FixedAssetsPage::class)
        ->assertDontSee('Dela upp i komponenter')
        ->assertDontSee('är inte uppdelad i komponenter')
        ->set('splitting', $building->id)
        ->set('splitDate', '2026-04-01')
        ->set('components', buildingComponents())
        ->call('split')
        ->assertHasErrors('split_date');
});

it('renders the fixed asset page with a fixed number of queries, whatever the number of components', function () {
    $count = function (int $buildings): int {
        foreach (range(1, $buildings) as $i) {
            $building = $this->assets->save($this->owner, $this->company, [
                'name' => "Fastighet {$i}", 'category' => 'BUILDINGS', 'acquired_on' => '2026-04-01', 'acquisition' => '1 200 000',
                'life_months' => '600', 'depreciation_start' => '2026-04-01', 'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820',
            ]);
            $this->assets->addAccounts($this->owner, $this->company);
            $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', buildingComponents());
        }

        $page = Livewire::test(FixedAssetsPage::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count(1);

    expect($count(4))->toBe($few);
});
