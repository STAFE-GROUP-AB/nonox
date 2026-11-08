<?php

declare(strict_types=1);

use App\Actions\Assets\FixedAssets;
use App\Domain\AnnualReport\AnnualAccounts;
use App\Domain\AnnualReport\K3Schedule;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\S1Fixture;

/*
 * S1-P4 (ADR-059 A.4, A.5): F-S1-a's component schedule (S1-R3, R3.2, R3.3,
 * R3.4) proposed by the fixed asset register and compared with the fixture
 * to the öre. The fixture's own component depreciation vouchers
 * (proposal_kind component_depreciation) are left out; the register books
 * them instead, one voucher per month.
 *
 * Building B1 is registered as one asset with ten years written off at
 * 2024-12-31 and split on 2025-01-01, the first day of a K3 year, with each
 * component's own cost and history (the fixture keeps them since 2015). Land
 * is in the ledger on 1130 already, so it is its own asset in the register
 * (K2 10.22 allows the same), and is never depreciated.
 */

/** Fixture component id => [name, cost, accumulated at 2024-12-31, remaining months from 2025-01-01] */
const F_S1_A_COMPONENTS = [
    'B1-stomme' => ['Stomme', '14 400 000', '1 800 000', 840],
    'B1-tak' => ['Tak', '2 160 000', '720 000', 240],
    'B1-fasad' => ['Fasad', '1 920 000', '480 000', 360],
    'B1-installationer' => ['Installationer (stammar, el, ventilation)', '3 600 000', '1 440 000', 180],
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    $this->fixture = S1Fixture::load('f-s1-a', fn (array $voucher): bool => ($voucher['proposal_kind'] ?? null) !== 'component_depreciation');
    $this->dropped = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-a/vouchers.json')), true)['vouchers'])
        ->filter(fn (array $voucher): bool => ($voucher['proposal_kind'] ?? null) === 'component_depreciation');
    CurrentCompany::set($this->fixture->company);
    $this->actingAs($this->fixture->owner);

    $owner = $this->fixture->owner;
    $company = $this->fixture->company;
    $assets = app(FixedAssets::class);
    $this->land = $assets->save($owner, $company, [
        'name' => 'Mark, Fiktiva Verkstaden 1:1', 'category' => 'LAND', 'acquired_on' => '2015-01-01', 'acquisition' => '4 000 000',
        'depreciation_start' => '2015-01-01', 'asset_account' => '1130',
    ]);
    $building = $assets->save($owner, $company, [
        'name' => 'Fabriksbyggnad', 'category' => 'BUILDINGS', 'acquired_on' => '2015-01-01', 'acquisition' => '22 080 000',
        'opening_accumulated' => '4 440 000', 'life_months' => '600', 'depreciation_start' => '2015-01-01',
        'asset_account' => '1111', 'accumulated_account' => '1119', 'expense_account' => '7820',
    ]);
    $this->building = $assets->split($owner, $company, $building->id, '2025-01-01', array_values(array_map(fn (array $c): array => [
        'name' => $c[0], 'kind' => 'part', 'cost' => $c[1], 'accumulated' => $c[2], 'life_months' => (string) $c[3], 'residual' => '', 'asset_account' => '',
    ], F_S1_A_COMPONENTS)));

    $this->vouchers = [];

    foreach (range(0, 23) as $i) {
        $month = CarbonImmutable::parse('2025-01-01')->addMonths($i)->format('Y-m');
        $this->vouchers[$month] = $assets->depreciate($owner, $company, $month);
    }
});

it('S1-R3 books 7820 per component every month exactly as F-S1-a, 37 000 kr a month', function () {
    $names = array_combine(array_column(F_S1_A_COMPONENTS, 0), array_keys(F_S1_A_COMPONENTS));
    $components = FixedAsset::query()->where('parent_id', $this->building->id)->get()->keyBy('id');

    foreach ($this->dropped as $fixture) {
        $month = substr($fixture['date'], 0, 7);
        $voucher = $this->vouchers[$month]->load('rows.account');
        $want = collect($fixture['rows'])->where('account', 7820)->mapWithKeys(fn (array $row): array => [$row['component_id'] => $row['debit_ore']])->all();
        $got = $voucher->rows->filter(fn ($row) => $row->account->number === 7820)
            ->mapWithKeys(fn ($row): array => [$names[preg_replace('/^\d+ /', '', (string) $row->description)] => $row->debit_ore->ore])->all();

        expect($voucher->voucher_date->toDateString())->toBe($fixture['date'])
            ->and($got)->toEqualCanonicalizing($want)
            ->and(array_sum($got))->toBe(3_700_000);
    }

    expect($this->dropped)->toHaveCount(24)
        ->and($components)->toHaveCount(4);
});

it('S1-R3 S1-R3.7 matches the component schedule of expected.json per year, to the öre', function () {
    $expected = collect($this->fixture->expected['component_depreciation']['building_B1'])->keyBy('component_id');
    $components = FixedAsset::query()->where('parent_id', $this->building->id)->withSum('depreciations', 'amount_ore')->get()->keyBy('name');

    foreach (F_S1_A_COMPONENTS as $id => [$name]) {
        $component = $components[$name];

        foreach (['2025', '2026'] as $year) {
            $want = $expected[$id]['per_ar'][$year];
            $booked = FixedAssetDepreciation::query()->where('fixed_asset_id', $component->id)->where('period', 'like', "{$year}-%")->sum('amount_ore');
            $before = FixedAssetDepreciation::query()->where('fixed_asset_id', $component->id)->where('period', '<', "{$year}-01")->sum('amount_ore');

            expect($component->acquisition_ore->ore)->toBe($want['anskaffningsvarde'], "{$id} {$year} cost")
                ->and((int) $booked)->toBe($want['arets_avskrivning'], "{$id} {$year} depreciation")
                ->and($component->opening_accumulated_ore->ore + (int) $before)->toBe($want['ackumulerade_avskrivningar_ib'], "{$id} {$year} opening")
                ->and($component->opening_accumulated_ore->ore + (int) $before + (int) $booked)->toBe($want['ackumulerade_avskrivningar_ub'], "{$id} {$year} closing");
        }

        expect($component->bookValueOre())->toBe($expected[$id]['per_ar']['2026']['redovisat_varde_ub']);
    }

    // S1-R3.2: land is never depreciated.
    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $this->land->id)->exists())->toBeFalse()
        ->and($this->land->fresh()->bookValueOre())->toBe($expected['B1-mark']['per_ar']['2026']['redovisat_varde_ub'])
        // The split building itself gets no depreciation after the split.
        ->and(FixedAssetDepreciation::query()->where('fixed_asset_id', $this->building->id)->exists())->toBeFalse();
});

it('S1-R3 gives 7820 for the year and Byggnader och mark at all three dates as F-S1-a (20 752 000 kr at 2026-12-31)', function () {
    $totals = $this->fixture->expected['component_depreciation']['totals'];
    $sheet = $this->fixture->expected['balance_sheet'];
    $first = AnnualAccounts::for($this->fixture->years[2025], K3Schedule::class);
    $second = AnnualAccounts::for($this->fixture->years[2026], K3Schedule::class);
    $buildings = fn (array $balance, string $field): int => (int) collect($balance['sections'])->flatMap(fn (array $section): array => $section['lines'])->firstWhere('key', 'buildings')[$field];

    expect($first->movementOf(7820, 7820))->toBe($totals['2025']['byggnad_7820'])
        ->and($second->movementOf(7820, 7820))->toBe($totals['2026']['byggnad_7820'])
        ->and($buildings($first->balanceSheet(), 'previous'))->toBe($sheet['2024-12-31']['tillgangar']['byggnader_och_mark'])
        ->and($buildings($second->balanceSheet(), 'previous'))->toBe($sheet['2025-12-31']['tillgangar']['byggnader_och_mark'])
        ->and($buildings($second->balanceSheet(), 'amount'))->toBe($sheet['2026-12-31']['tillgangar']['byggnader_och_mark'])
        ->and($sheet['2026-12-31']['tillgangar']['byggnader_och_mark'])->toBe(2_075_200_000);
});
