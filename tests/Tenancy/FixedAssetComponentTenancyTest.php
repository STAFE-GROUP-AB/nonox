<?php

declare(strict_types=1);

use App\Actions\Assets\FixedAssets;
use App\Livewire\Accounting\FixedAssetsPage;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for components in the fixed asset register (ADR-059 A.5,
 * S1-P4): another company's asset id answers 404 when splitting, from the
 * action and from the page, and a component can never belong to another
 * company's asset, not even through the database.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();

    foreach ([$this->a, $this->b] as $company) {
        $company->forceFill(['reporting_framework' => 'K3'])->save();
        CurrentCompany::run($company, fn () => FiscalYear::query()->update(['reporting_framework' => 'K3']));
    }

    $this->assets = app(FixedAssets::class);
    $this->buildingB = CurrentCompany::run($this->b, fn () => $this->assets->save($this->ownerB, $this->b, [
        'name' => 'Hemlig fastighet', 'category' => 'BUILDINGS', 'acquired_on' => '2026-04-01', 'acquisition' => '1 000 000',
        'life_months' => '600', 'depreciation_start' => '2026-04-01', 'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820',
    ]));
    $this->parts = [
        ['name' => 'Stomme', 'kind' => 'part', 'cost' => '600 000', 'life_months' => '600'],
        ['name' => 'Tak', 'kind' => 'part', 'cost' => '400 000', 'life_months' => '300'],
    ];
});

it('answers 404 for another company\'s asset when splitting', function () {
    expect(fn () => $this->assets->split($this->ownerA, $this->a, $this->buildingB->id, '2026-04-01', $this->parts))
        ->toThrow(ModelNotFoundException::class)
        ->and(CurrentCompany::run($this->b, fn () => FixedAsset::query()->count()))->toBe(1)
        ->and(CurrentCompany::run($this->b, fn () => $this->buildingB->fresh()->status))->toBe(FixedAsset::ACTIVE);
});

it('answers 404 from the page for another company\'s asset, also with a tampered id', function () {
    $this->actingAs($this->ownerA);
    CurrentCompany::set($this->a);

    Livewire::test(FixedAssetsPage::class)->call('startSplit', $this->buildingB->id)->assertNotFound();
    Livewire::test(FixedAssetsPage::class)
        ->set('splitting', $this->buildingB->id)
        ->set('splitDate', '2026-04-01')
        ->set('components', $this->parts)
        ->call('split')
        ->assertNotFound();

    Livewire::test(FixedAssetsPage::class)->assertDontSee('Hemlig fastighet');
});

it('keeps components inside their company, and a child of another company\'s asset is impossible', function () {
    CurrentCompany::run($this->b, fn () => $this->assets->split($this->ownerB, $this->b, $this->buildingB->id, '2026-04-01', $this->parts));

    expect(CurrentCompany::run($this->a, fn () => FixedAsset::query()->whereNotNull('parent_id')->count()))->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => FixedAsset::query()->whereNotNull('parent_id')->count()))->toBe(2);

    $row = (array) DB::table('fixed_assets')->where('parent_id', $this->buildingB->id)->first();
    unset($row['id']);

    expect(fn () => DB::table('fixed_assets')->insert([...$row, 'company_id' => $this->a->id, 'number' => 99]))
        ->toThrow(QueryException::class);
});
