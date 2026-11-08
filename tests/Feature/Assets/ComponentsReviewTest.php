<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Assets\FixedAssets;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\FiscalYearStatus;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Livewire\Accounting\FixedAssetsPage;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * S1-P4 after the cold review of 2026-11-08 (ADR-059 A.2.4, "As built"):
 * total life per component, the plan-mismatch message, locked periods and
 * closed years, component accounts, the residual value pre-fill, and
 * proposals of land and long-lived buildings from purchases.
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

/** @param array<string, string> $overrides */
function reviewBuilding(array $overrides = []): FixedAsset
{
    $building = test()->assets->save(test()->owner, test()->company, [
        'name' => 'Verkstadshus', 'category' => 'BUILDINGS', 'acquired_on' => '2026-04-01', 'acquisition' => '1 000 000',
        'life_months' => '600', 'depreciation_start' => '2026-04-01',
        'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820',
        ...$overrides,
    ]);
    test()->assets->addAccounts(test()->owner, test()->company);

    return $building;
}

/** @return list<array<string, string>> */
function reviewParts(array $overrides = []): array
{
    return array_replace_recursive([
        ['name' => 'Stomme', 'kind' => 'part', 'cost' => '600 000', 'life_months' => '960'],
        ['name' => 'Tak', 'kind' => 'part', 'cost' => '400 000', 'life_months' => '360'],
    ], $overrides);
}

it('K3 17.23 stores the total useful life per component and prints it in the principles, not the remaining life', function () {
    // Bought 2016 and written off ten years before the register; split 2026-04-01 with 123 months gone.
    $building = reviewBuilding(['acquired_on' => '2016-01-01', 'depreciation_start' => '2016-01-01', 'opening_accumulated' => '205 000']);
    foreach (['2026-01', '2026-02', '2026-03'] as $month) {
        $this->assets->depreciate($this->owner, $this->company, $month);
    }

    $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', reviewParts([
        0 => ['life_months' => '837'],
        1 => ['life_months' => '237', 'total_life_months' => '480'],
    ]));
    $components = FixedAsset::query()->where('parent_id', $building->id)->orderBy('number')->get();

    // Blank: the remaining life plus the months since the asset's first depreciation month (837 + 123 = 960).
    expect($components->map(fn (FixedAsset $c) => [$c->useful_life_months, $c->total_useful_life_months])->all())->toBe([[837, 960], [237, 480]])
        ->and((new ReflectionMethod(AnnualReportDocument::class, 'depreciationPrinciples'))->invoke(null))->toBe(['Byggnader: 40–80 år']);
});

it('refuses a total life shorter than the remaining life', function () {
    $building = reviewBuilding();

    expect(fn () => $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', reviewParts([0 => ['total_life_months' => '100']])))
        ->toThrow(ValidationException::class, 'Den totala nyttjandeperioden kan inte vara kortare än den återstående');
});

it('says the register\'s plan must match what is booked when the asset has no booked months, and to book the months when it has', function () {
    // Registered in 2016 with less written off than its plan, and never depreciated in the register.
    $unmatched = reviewBuilding(['acquired_on' => '2016-01-01', 'depreciation_start' => '2016-01-01', 'opening_accumulated' => '100 000']);

    expect(fn () => $this->assets->split($this->owner, $this->company, $unmatched->id, '2026-04-01', reviewParts()))
        ->toThrow(ValidationException::class, 'Registrets plan för Verkstadshus stämmer inte med det som redan är avskrivet');

    try {
        $this->assets->split($this->owner, $this->company, $unmatched->id, '2026-04-01', reviewParts());
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->implode(' '))->not->toContain('Bokför avskrivningarna');
    }

    $booked = reviewBuilding(['name' => 'Lager', 'acquired_on' => '2026-01-01', 'depreciation_start' => '2026-01-01']);
    $this->assets->depreciate($this->owner, $this->company, '2026-01');

    expect(fn () => $this->assets->split($this->owner, $this->company, $booked->id, '2026-04-01', reviewParts()))
        ->toThrow(ValidationException::class, 'Bokför avskrivningarna till och med 2026-03 innan tillgången delas upp.');
});

it('refuses a split in a locked period or a closed fiscal year', function () {
    $building = reviewBuilding();
    $this->company->forceFill(['locked_until_date' => '2026-04-30'])->save();

    expect(fn () => $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', reviewParts()))
        ->toThrow(ValidationException::class, 'Perioden till och med 2026-04-30 är låst');

    $this->company->forceFill(['locked_until_date' => null])->save();
    $this->year->forceFill(['status' => FiscalYearStatus::Closed])->save();

    expect(fn () => $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', reviewParts()))
        ->toThrow(ValidationException::class, 'Räkenskapsåret 2026 är stängt');
});

it('keeps a component on a fixed asset account, never an accumulated depreciation account', function (string $account, bool $allowed) {
    $building = reviewBuilding();
    Account::query()->firstOrCreate(['number' => 1112], ['name' => 'Byggnadsinventarier', 'type' => AccountType::forNumber(1112)]);
    $split = fn () => $this->assets->split($this->owner, $this->company, $building->id, '2026-04-01', reviewParts([1 => ['asset_account' => $account]]));

    $allowed
        ? expect($split()->status)->toBe(FixedAsset::SPLIT)
        : expect($split)->toThrow(ValidationException::class, 'Tillgångskontot för en komponent ska vara ett konto för materiella anläggningstillgångar');
})->with([
    '1112' => ['1112', true],
    '1119' => ['1119', false],
    '1229' => ['1229', false],
    '1310' => ['1310', false],
    '1099' => ['1099', false],
]);

it('pre-fills the asset\'s residual value across the parts by the largest remainder, and shows it in the review', function () {
    $building = reviewBuilding(['residual' => '100 000,01']);

    $page = Livewire::test(FixedAssetsPage::class)
        ->call('startSplit', $building->id)
        ->call('removeComponent', 4)->call('removeComponent', 3)
        ->set('components.0.cost', '100 000')
        ->set('components.1.cost', '450 000')
        ->set('components.2.cost', '450 000');

    // Land gets none; 100 000,01 kr over 450 000 : 450 000 → 50 000,01 and 50 000,00.
    $page->assertSet('components.0.residual', '')
        ->assertSet('components.1.residual', Money::ofOre(5_000_001)->format(withCurrency: false))
        ->assertSet('components.2.residual', Money::ofOre(5_000_000)->format(withCurrency: false))
        ->call('reviewSplit')
        ->assertSee('Restvärde')
        ->assertSee(Money::ofOre(5_000_001)->format())
        ->assertSeeHtml('x-init="$nextTick(() => $refs.confirmSplit.focus())"')
        ->assertSeeHtml('x-ref="confirmSplit"');
});

it('proposes land from a purchase without a life, and a building with a life longer than 50 years', function () {
    $this->assets->saveSettings($this->owner, $this->company, '1110, 1130, 1220', '');
    foreach ([1110 => 'Byggnader', 1119 => 'Ackumulerade avskrivningar på byggnader', 1130 => 'Mark', 7820 => 'Avskrivningar på byggnader och markanläggningar'] as $number => $name) {
        Account::query()->firstOrCreate(['number' => $number], ['name' => $name, 'type' => AccountType::forNumber($number)]);
    }

    $draft = app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-20',
        'description' => 'Köp av fastighet',
        'rows' => [
            ['account' => 1130, 'debit_ore' => 20_000_000, 'credit_ore' => 0, 'description' => 'Tomt', 'asset' => ['name' => 'Tomt', 'category' => 'LAND', 'acquisition' => '', 'life_months' => '', 'acquired_on' => '', 'depreciation_start' => '']],
            ['account' => 1110, 'debit_ore' => 80_000_000, 'credit_ore' => 0, 'description' => 'Hus', 'asset' => ['name' => 'Hus', 'category' => 'BUILDINGS', 'acquisition' => '', 'life_months' => '900', 'acquired_on' => '', 'depreciation_start' => '']],
            ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 100_000_000],
        ],
        'with_assets' => true,
    ]);
    app(PostVoucher::class)($this->owner, $draft);

    $land = FixedAsset::query()->where('name', 'Tomt')->sole();
    $house = FixedAsset::query()->where('name', 'Hus')->sole();

    expect([$land->category->value, $land->useful_life_months, $land->accumulated_account, $land->expense_account])->toBe(['LAND', 0, null, null])
        ->and([$house->category->value, $house->useful_life_months, $house->accumulated_account])->toBe(['BUILDINGS', 900, 1119]);
});

it('refuses a proposal without a life for an asset that is depreciated, and above the category\'s longest life', function () {
    $this->assets->saveSettings($this->owner, $this->company, '1110, 1220', '');
    Account::query()->firstOrCreate(['number' => 1110], ['name' => 'Byggnader', 'type' => AccountType::forNumber(1110)]);
    $propose = fn (string $category, string $life, int $account) => app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-03-20',
        'description' => 'Inköp',
        'rows' => [
            ['account' => $account, 'debit_ore' => 1_000_000, 'credit_ore' => 0, 'description' => 'Inköp', 'asset' => ['name' => 'Inköp', 'category' => $category, 'acquisition' => '', 'life_months' => $life, 'acquired_on' => '', 'depreciation_start' => '']],
            ['account' => 1930, 'debit_ore' => 0, 'credit_ore' => 1_000_000],
        ],
        'with_assets' => true,
    ]);

    expect(fn () => $propose('EQUIPMENT', '', 1220))->toThrow(ValidationException::class)
        ->and(fn () => $propose('EQUIPMENT', '900', 1220))->toThrow(ValidationException::class, '600')
        ->and(fn () => $propose('BUILDINGS', '1300', 1110))->toThrow(ValidationException::class, '1200');
});
