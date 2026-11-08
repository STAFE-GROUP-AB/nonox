<?php

declare(strict_types=1);

use App\Actions\AnnualReport\SaveAnnualReport;
use App\Actions\Assets\FixedAssets;
use App\Domain\AnnualReport\AnnualReportDocument;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

/*
 * S1-P4 hot spot (ADR-059 A.3): the K2 principles note lists the useful
 * lives per kind of asset. Land is never depreciated and has no life, and an
 * asset split into components is depreciated through them: neither may print
 * a line such as "Mark: 0 år".
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $this->assets = app(FixedAssets::class);
});

it('does not print land in the K2 principles note, nor "0 år"', function () {
    $this->assets->save($this->owner, $this->company, ['name' => 'Kaffemaskin', 'category' => 'EQUIPMENT', 'acquired_on' => '2026-10-01', 'acquisition' => '36 000', 'life_months' => '36', 'depreciation_start' => '2026-10-01', 'asset_account' => '1220', 'accumulated_account' => '1229', 'expense_account' => '7832']);
    $this->assets->save($this->owner, $this->company, ['name' => 'Tomt', 'category' => 'LAND', 'acquired_on' => '2026-03-01', 'acquisition' => '500 000', 'depreciation_start' => '2026-03-01', 'asset_account' => '1130']);
    $this->assets->save($this->owner, $this->company, ['name' => 'Lagerbyggnad', 'category' => 'BUILDINGS', 'acquired_on' => '2026-03-01', 'acquisition' => '2 000 000', 'life_months' => '600', 'depreciation_start' => '2026-03-01', 'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820']);
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => '0']);

    $principles = AnnualReportDocument::data($this->company, $this->year, $report)['depreciation'];

    expect($principles)->toBe(['Inventarier och verktyg: 3 år', 'Byggnader: 50 år'])
        ->and(implode(' ', $principles))->not->toContain('Mark')->not->toMatch('/(?<![0-9])0 år/');
});

it('leaves a split asset out of the principles and reads them with one query', function () {
    $building = $this->assets->save($this->owner, $this->company, ['name' => 'Lagerbyggnad', 'category' => 'BUILDINGS', 'acquired_on' => '2026-03-01', 'acquisition' => '2 000 000', 'life_months' => '600', 'depreciation_start' => '2026-03-01', 'asset_account' => '1110', 'accumulated_account' => '1119', 'expense_account' => '7820']);
    $building->forceFill(['status' => FixedAsset::SPLIT])->save();
    $report = app(SaveAnnualReport::class)($this->owner, $this->year, ['average_employees' => '0']);

    expect(AnnualReportDocument::data($this->company, $this->year, $report)['depreciation'])->toBe([]);

    $method = new ReflectionMethod(AnnualReportDocument::class, 'depreciationPrinciples');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $method->invoke(null);

    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
});
