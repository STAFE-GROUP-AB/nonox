<?php

declare(strict_types=1);

use App\Actions\Assets\FixedAssets;
use App\Livewire\Accounting\FixedAssetsPage;
use App\Models\FixedAsset;
use App\Models\FixedAssetAddition;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for additions to fixed assets: another company's voucher
 * row or asset answers 404, from the action and from the page, and an
 * addition can never point at another company's asset, not even through the
 * database.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-10 10:00:00'));
    [$this->ownerA, $this->a] = Bookkeeping::company();
    [$this->ownerB, $this->b] = Bookkeeping::company();
    $this->assets = app(FixedAssets::class);

    $asset = fn ($owner, $company, string $name): FixedAsset => CurrentCompany::run($company, function () use ($owner, $company, $name): FixedAsset {
        $this->assets->addAccounts($owner, $company);

        return $this->assets->save($owner, $company, [
            'name' => $name, 'category' => 'EQUIPMENT', 'acquired_on' => '2026-01-10', 'acquisition' => '60 000',
            'life_months' => '60', 'depreciation_start' => '2026-01-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832',
        ]);
    });

    $this->assetA = $asset($this->ownerA, $this->a, 'Köket');
    $this->assetB = $asset($this->ownerB, $this->b, 'Hemligt kök');
    $this->rowB = CurrentCompany::run($this->b, fn (): VoucherRow => Bookkeeping::postRows($this->ownerB, $this->b, '2026-03-15', [1220 => 1_000_000, 1930 => -1_000_000])->rows()->where('debit_ore', '>', 0)->sole());
    $this->rowA = CurrentCompany::run($this->a, fn (): VoucherRow => Bookkeeping::postRows($this->ownerA, $this->a, '2026-03-15', [1220 => 1_000_000, 1930 => -1_000_000])->rows()->where('debit_ore', '>', 0)->sole());
});

it('answers 404 for another company\'s voucher row or asset', function () {
    CurrentCompany::set($this->a);

    expect(fn () => $this->assets->addPurchase($this->ownerA, $this->a, $this->assetA->id, $this->rowB->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->assets->addPurchase($this->ownerA, $this->a, $this->assetB->id, $this->rowA->id))->toThrow(ModelNotFoundException::class)
        ->and(CurrentCompany::run($this->b, fn () => FixedAssetAddition::query()->count()))->toBe(0)
        ->and(CurrentCompany::run($this->b, fn () => $this->assetB->fresh()->acquisition_ore->ore))->toBe(6_000_000);
});

it('answers 404 from the page, also with a tampered id, and never lists another company\'s rows', function () {
    $this->actingAs($this->ownerA);
    CurrentCompany::set($this->a);

    Livewire::test(FixedAssetsPage::class)->call('startAddition', $this->assetB->id)->assertNotFound();
    Livewire::test(FixedAssetsPage::class)
        ->set('adding', $this->assetA->id)
        ->set('additionRow', (string) $this->rowB->id)
        ->call('addPurchase')
        ->assertNotFound();

    $candidates = FixedAssets::additionCandidates($this->assetA);

    expect($candidates->pluck('id')->all())->toBe([$this->rowA->id])
        ->and(FixedAssetAddition::query()->count())->toBe(0);
});

it('keeps an addition inside its company, even through the database', function () {
    CurrentCompany::run($this->b, fn () => $this->assets->addPurchase($this->ownerB, $this->b, $this->assetB->id, $this->rowB->id));

    expect(CurrentCompany::run($this->a, fn () => FixedAssetAddition::query()->count()))->toBe(0);

    $row = (array) DB::table('fixed_asset_additions')->where('fixed_asset_id', $this->assetB->id)->first();
    unset($row['id'], $row['voucher_row_id']);

    expect(fn () => DB::table('fixed_asset_additions')->insert([...$row, 'company_id' => $this->a->id]))
        ->toThrow(QueryException::class);
});
