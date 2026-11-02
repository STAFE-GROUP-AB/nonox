<?php

declare(strict_types=1);

/*
 * Yearly VAT shows its last day on the overviews, with EU trade read from the
 * ledger (SFL 26 kap. 33, 33 a och 33 b §§, read 2026-11-02). Enligt
 * granskning 2026-11-02, juristgranskning återstår (AUD-MOMS-9, #60).
 */

use App\Domain\Company\CompanyOverview;
use App\Domain\Company\VatPeriod;
use App\Domain\Vat\EuTrade;
use App\Models\Account;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['legal_form' => 'AB', 'vat_registered' => true, 'vat_period' => VatPeriod::Yearly])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->travelTo(CarbonImmutable::parse('2027-01-10 10:00:00'));
});

function yearlyVatDue(): ?string
{
    $tasks = collect(app(CompanyOverview::class)->for(test()->owner->fresh())[0]['tasks']);

    return $tasks->firstWhere('destination', 'vat')['due'] ?? null;
}

it('gives an AB with yearly VAT and no EU trade the electronic date of 33 b §: 17 August after a calendar year', function () {
    Bookkeeping::post($this->owner, $this->company, 125_000, 1930, 3001, '2026-03-01');

    expect(EuTrade::years([$this->company->id]))->toBe([])
        ->and(yearlyVatDue())->toBe('2027-08-17');
});

it('moves the date to 26 February when the year had EU trade (33 b § tredje stycket)', function () {
    // A purchase of goods from another EU country, box 20 (account 4515).
    if (! Account::query()->where('number', 4515)->exists()) {
        Account::create(['number' => 4515, 'name' => 'Inköp av varor från annat EU-land, 25 %', 'type' => 'EXPENSE']);
    }

    Bookkeeping::post($this->owner, $this->company, 100_000, 4515, 2440, '2026-05-01');

    expect(EuTrade::years([$this->company->id]))->toBe(["{$this->company->id}:2026-01-01"])
        ->and(yearlyVatDue())->toBe('2027-02-26');
});

it('gives a sole trader 12 May the year after (33 a §)', function () {
    $this->company->forceFill(['legal_form' => 'EF'])->save();

    expect(yearlyVatDue())->toBe('2027-05-12');
});
