<?php

declare(strict_types=1);

/*
 * Boxes 20 and 23 of the momsdeklaration read BAS 2026's trading-goods
 * accounts too: 4075–4077 Inköp av handelsvaror från annat EU-land and
 * 4065–4067 Inköp av handelsvaror i Sverige, omvänd betalningsskyldighet,
 * besides the raw-material accounts 4515–4517 and 4415–4417.
 */

use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatBoxes;
use App\Domain\Vat\VatReturn;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['vat_period' => VatPeriod::Quarterly])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

it('reads BAS 2026\'s trading-goods accounts into box 20 and box 23', function () {
    foreach ([4075 => 'Inköp av handelsvaror från annat EU-land, 25 % moms', 4065 => 'Inköp av handelsvaror i Sverige, omvänd betalningsskyldighet, 25 % moms', 2614 => 'Utgående moms omvänd betalskyldighet, 25 %', 2645 => 'Beräknad ingående moms på förvärv från utlandet', 2647 => 'Ingående moms omvänd betalningsskyldighet varor och tjänster i Sverige'] as $number => $name) {
        if (! Account::query()->where('number', $number)->exists()) {
            Account::create(['number' => $number, 'name' => $name, 'type' => $number >= 4000 ? 'EXPENSE' : 'LIABILITY_EQUITY']);
        }
    }

    Bookkeeping::postRows($this->owner, $this->company, '2026-02-10', [4075 => 10_000_000, 2645 => 2_500_000, 2614 => -2_500_000, 2440 => -10_000_000]);
    Bookkeeping::postRows($this->owner, $this->company, '2026-02-11', [4065 => 4_000_000, 2647 => 1_000_000, 2614 => -1_000_000, 2440 => -4_000_000]);

    $boxes = VatReturn::calculate($this->year, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-03-31'))->boxes();

    expect($boxes[20])->toBe(100_000)
        ->and($boxes[23])->toBe(40_000)
        ->and($boxes[30])->toBe(35_000)
        ->and($boxes[48])->toBe(35_000)
        ->and(VatBoxes::boxFor(4077))->toBe(20)
        ->and(VatBoxes::boxFor(4067))->toBe(23)
        ->and(VatBoxes::boxFor(4070))->toBeNull()
        ->and(VatBoxes::boxFor(4060))->toBeNull();
});
